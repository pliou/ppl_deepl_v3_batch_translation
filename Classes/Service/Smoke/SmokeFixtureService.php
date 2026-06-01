<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Service\Smoke;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class SmokeFixtureService
{
    public const SITE_IDENTIFIER = 'bt_smoke_site';
    public const SITE_TITLE = 'Batch Translation Smoke Site';
    public const TARGET_LANGUAGE_ID = 1;
    public const THIRD_LANGUAGE_ID = 2;
    public const ADMIN_USERNAME = 'bt_admin';
    public const LIMITED_USERNAME = 'bt_limited';
    public const PASSWORD = 'BatchSmoke123!';

    public function __construct(
        private readonly ConnectionPool $connectionPool
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function resetAndCreate(string $artifactRoot): array
    {
        $this->softDeleteExistingFixture();
        $this->upsertBackendUsers();

        $now = time();
        $map = [
            'siteIdentifier' => self::SITE_IDENTIFIER,
            'sourceLanguageId' => 0,
            'targetLanguageId' => self::TARGET_LANGUAGE_ID,
            'thirdLanguageId' => self::THIRD_LANGUAGE_ID,
            'pages' => [],
            'content' => [],
            'backendUsers' => [
                'admin' => self::ADMIN_USERNAME,
                'limited' => self::LIMITED_USERNAME,
                'password' => self::PASSWORD,
            ],
        ];

        $root = $this->insertPage(0, 'BT Smoke Root', '/', 256, $now);
        $this->writeSiteConfiguration($root);
        $this->writeRootTemplate($root, $now);
        $map['pages']['root'] = $root;
        $batch = $this->insertPage($root, 'Batch-Tests', '/batch-tests', 256, $now, 'Deutscher Smoke Text fuer Batch-Tests');
        $team = $this->insertPage($batch, 'Teamnotizen', '/batch-tests/team-notes', 256, $now, 'Deutscher Smoke Text fuer Teamnotizen');
        $launch = $this->insertPage($team, 'Startaufgaben', '/batch-tests/team-notes/launch-tasks', 256, $now);
        $support = $this->insertPage($team, 'Support-Ideen', '/batch-tests/team-notes/support-ideas', 256, $now);
        $editorial = $this->insertPage($team, 'Redaktionsplan', '/batch-tests/team-notes/editorial-plan', 256, $now);
        $service = $this->insertPage($root, 'Servicedesk', '/service-desk', 256, $now);
        $offers = $this->insertPage($root, 'Lokale Angebote', '/local-offers', 256, $now);
        $standalone = $this->insertPage($root, 'Einzelseite', '/standalone-page', 256, $now);
        $blocked = $this->insertPage($root, 'Gesperrter Berechtigungsbereich', '/permission-blocked-area', 0, $now);
        $blockedChild = $this->insertPage($blocked, 'Gesperrte Unterseite', '/permission-blocked-area/blocked-child-page', 0, $now);
        $existing = $this->insertPage($root, 'Bestehende Uebersetzungsseite', '/existing-translation-page', 256, $now);
        $partial = $this->insertPage($root, 'Teilweise Uebersetzungsseite', '/partial-translation-page', 256, $now, 'DE Teilweise', 'Noch leer fuellen');

        $map['pages'] += [
            'batchTests' => $batch,
            'teamNotes' => $team,
            'launchTasks' => $launch,
            'supportIdeas' => $support,
            'editorialPlan' => $editorial,
            'serviceDesk' => $service,
            'localOffers' => $offers,
            'standalonePage' => $standalone,
            'permissionBlockedArea' => $blocked,
            'blockedChildPage' => $blockedChild,
            'existingTranslationPage' => $existing,
            'partialTranslationPage' => $partial,
        ];

        foreach ([$batch, $team, $launch, $support, $editorial, $service, $offers, $standalone, $blocked, $blockedChild, $existing, $partial] as $pageUid) {
            $map['content'][(string)$pageUid] = $this->insertDefaultElements($pageUid, $now);
        }
        $map['content']['htmlBodytextElement'] = $this->insertContent($batch, 'HTML Hinweis', '<p>Dies ist <strong>wichtiger</strong> HTML Inhalt fuer den Smoke Test.</p>', $now, 'text', 99);

        $existingTarget = $this->insertTranslatedPage($existing, 'Existing EN title', 'Existing EN description', $now);
        $map['pages']['existingTranslationTarget'] = $existingTarget;
        foreach ($map['content'][(string)$existing] as $code => $sourceContentUid) {
            $map['content'][(string)$existing . ':target'][$code] = $this->insertTranslatedContent($existing, $sourceContentUid, 'Existing EN ' . $code, 'Existing EN body ' . $code, $now);
        }

        $partialTarget = $this->insertTranslatedPage($partial, 'Partial EN title', '', $now);
        $map['pages']['partialTranslationTarget'] = $partialTarget;
        foreach ($map['content'][(string)$partial] as $code => $sourceContentUid) {
            $map['content'][(string)$partial . ':target'][$code] = $this->insertTranslatedContent($partial, $sourceContentUid, 'Partial EN ' . $code, '', $now);
        }

        $this->writeJson($artifactRoot . '/fixture-uids.json', $map);

        return $map;
    }

    /**
     * @param array<string, mixed> $map
     */
    public function restoreTargetState(array &$map, string $artifactRoot): void
    {
        $now = time();
        $sourcePageUids = [];
        foreach (is_array($map['pages'] ?? null) ? $map['pages'] : [] as $key => $uid) {
            if (str_ends_with((string)$key, 'Target')) {
                continue;
            }
            $sourcePageUids[] = (int)$uid;
        }
        $sourceContentUids = [];
        foreach (is_array($map['content'] ?? null) ? $map['content'] : [] as $key => $value) {
            if (str_contains((string)$key, ':target')) {
                continue;
            }
            if (is_array($value)) {
                foreach ($value as $contentUid) {
                    $sourceContentUids[] = (int)$contentUid;
                }
            } elseif (is_numeric($value)) {
                $sourceContentUids[] = (int)$value;
            }
        }

        $this->deleteTranslations('pages', 'l10n_parent', $sourcePageUids);
        $this->deleteTranslations('tt_content', 'l18n_parent', $sourceContentUids);

        $existing = (int)$map['pages']['existingTranslationPage'];
        $partial = (int)$map['pages']['partialTranslationPage'];
        $map['pages']['existingTranslationTarget'] = $this->insertTranslatedPage($existing, 'Existing EN title', 'Existing EN description', $now);
        $map['pages']['partialTranslationTarget'] = $this->insertTranslatedPage($partial, 'Partial EN title', '', $now);

        foreach (['existingTranslationPage' => $existing, 'partialTranslationPage' => $partial] as $pageKey => $sourcePageUid) {
            $targetKey = (string)$sourcePageUid . ':target';
            $map['content'][$targetKey] = [];
            foreach ($map['content'][(string)$sourcePageUid] as $code => $sourceContentUid) {
                $header = $pageKey === 'existingTranslationPage' ? 'Existing EN ' . $code : 'Partial EN ' . $code;
                $body = $pageKey === 'existingTranslationPage' ? 'Existing EN body ' . $code : '';
                $map['content'][$targetKey][$code] = $this->insertTranslatedContent($sourcePageUid, (int)$sourceContentUid, $header, $body, $now);
            }
        }

        $this->writeJson($artifactRoot . '/fixture-uids.json', $map);
    }

    public function adminBackendUser(): BackendUserAuthentication
    {
        return $this->backendUser([
            'uid' => 999001,
            'username' => self::ADMIN_USERNAME,
            'admin' => 1,
            'lang' => 'en',
        ]);
    }

    public function limitedBackendUser(): BackendUserAuthentication
    {
        return $this->backendUser([
            'uid' => 999002,
            'username' => self::LIMITED_USERNAME,
            'admin' => 0,
            'lang' => 'en',
            'tables_modify' => 'pages,tt_content',
            'allowed_languages' => '1',
        ]);
    }

    private function backendUser(array $user): BackendUserAuthentication
    {
        $backendUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $backendUser->user = $user;
        $backendUser->workspace = 0;

        return $backendUser;
    }

    private function softDeleteExistingFixture(): void
    {
        $now = time();
        $pageConnection = $this->connectionPool->getConnectionForTable('pages');
        $queryBuilder = $pageConnection->createQueryBuilder();
        $rows = $queryBuilder
            ->select('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, \Doctrine\DBAL\ParameterType::INTEGER)),
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq('title', $queryBuilder->createNamedParameter('BT Smoke Root')),
                    $queryBuilder->expr()->and(
                        $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter(1, \Doctrine\DBAL\ParameterType::INTEGER)),
                        $queryBuilder->expr()->eq('slug', $queryBuilder->createNamedParameter('/batch-tests')),
                        $queryBuilder->expr()->in('title', $queryBuilder->createNamedParameter(['Stapel Tests', 'Batch Tests', 'Batch-Tests'], \Doctrine\DBAL\ArrayParameterType::STRING))
                    )
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();
        foreach ($rows as $row) {
            $uids = $this->subtreeUids((int)$row['uid']);
            if ($uids === []) {
                continue;
            }
            $this->softDeleteByIn('pages', 'uid', $uids, $now);
            $this->softDeleteByIn('tt_content', 'pid', $uids, $now);
            $this->softDeleteByIn('sys_template', 'pid', $uids, $now);
        }
        $this->softDeleteOrphanedSmokePages($now);
    }

    private function softDeleteOrphanedSmokePages(int $now): void
    {
        $connection = $this->connectionPool->getConnectionForTable('pages');
        $uids = array_map('intval', $connection->executeQuery(
            'SELECT target.uid FROM pages target INNER JOIN pages source ON target.l10n_parent = source.uid'
            . ' WHERE target.sys_language_uid > ? AND target.deleted = ? AND source.deleted = ? AND target.title LIKE ?',
            [0, 0, 1, '[BT-SMOKE %'],
            [
                \Doctrine\DBAL\ParameterType::INTEGER,
                \Doctrine\DBAL\ParameterType::INTEGER,
                \Doctrine\DBAL\ParameterType::INTEGER,
                \Doctrine\DBAL\ParameterType::STRING,
            ]
        )->fetchFirstColumn());

        $this->softDeleteByIn('pages', 'uid', $uids, $now);
    }

    /**
     * @param int[] $sourceUids
     */
    private function deleteTranslations(string $table, string $parentField, array $sourceUids): void
    {
        $sourceUids = array_values(array_unique(array_filter(array_map('intval', $sourceUids))));
        if ($sourceUids === []) {
            return;
        }

        $connection = $this->connectionPool->getConnectionForTable($table);
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder
            ->delete($table)
            ->where(
                $queryBuilder->expr()->gt('sys_language_uid', $queryBuilder->createNamedParameter(0, \Doctrine\DBAL\ParameterType::INTEGER)),
                $queryBuilder->expr()->in($parentField, $queryBuilder->createNamedParameter($sourceUids, \Doctrine\DBAL\ArrayParameterType::INTEGER))
            )
            ->executeStatement();
    }

    /**
     * @param int[] $sourceUids
     */
    private function softDeleteTranslations(string $table, string $parentField, array $sourceUids, int $now): void
    {
        $sourceUids = array_values(array_unique(array_filter(array_map('intval', $sourceUids))));
        if ($sourceUids === []) {
            return;
        }

        $connection = $this->connectionPool->getConnectionForTable($table);
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder
            ->update($table)
            ->set('deleted', '1')
            ->set('tstamp', (string)$now)
            ->where(
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(self::TARGET_LANGUAGE_ID, \Doctrine\DBAL\ParameterType::INTEGER)),
                $queryBuilder->expr()->in($parentField, $queryBuilder->createNamedParameter($sourceUids, \Doctrine\DBAL\ArrayParameterType::INTEGER))
            )
            ->executeStatement();
    }

    /**
     * @param int[] $uids
     */
    private function softDeleteByIn(string $table, string $field, array $uids, int $now): void
    {
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids))));
        if ($uids === []) {
            return;
        }

        $connection = $this->connectionPool->getConnectionForTable($table);
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder
            ->update($table)
            ->set('deleted', '1')
            ->set('tstamp', (string)$now)
            ->where(
                $queryBuilder->expr()->in($field, $queryBuilder->createNamedParameter($uids, \Doctrine\DBAL\ArrayParameterType::INTEGER))
            )
            ->executeStatement();
    }

    /**
     * @return int[]
     */
    private function subtreeUids(int $rootUid): array
    {
        $connection = $this->connectionPool->getConnectionForTable('pages');
        $rows = $connection->select(['uid', 'pid'], 'pages', ['deleted' => 0])->fetchAllAssociative();
        $children = [];
        foreach ($rows as $row) {
            $children[(int)$row['pid']][] = (int)$row['uid'];
        }
        $uids = [];
        $stack = [$rootUid];
        while ($stack !== []) {
            $uid = array_pop($stack);
            $uids[] = $uid;
            foreach ($children[$uid] ?? [] as $childUid) {
                $stack[] = $childUid;
            }
        }

        return $uids;
    }

    private function writeSiteConfiguration(int $rootPageUid): void
    {
        $path = Environment::getProjectPath() . '/config/sites/' . self::SITE_IDENTIFIER . '/config.yaml';
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $yaml = <<<YAML
rootPageId: {$rootPageUid}
base: 'http://typo3-14.ddev.site/bt-smoke-root/'
websiteTitle: 'Batch Translation Smoke Site'
languages:
  -
    title: Deutsch
    enabled: true
    languageId: 0
    base: /
    locale: de_DE.UTF-8
    navigationTitle: Deutsch
    flag: de
    hreflang: de-DE
    websiteTitle: ''
  -
    title: English
    enabled: true
    languageId: 1
    base: /en/
    locale: en_US.UTF-8
    navigationTitle: English
    flag: us
    hreflang: en-US
    fallbackType: fallback
    fallbacks: '0'
    websiteTitle: ''
  -
    title: Francais
    enabled: true
    languageId: 2
    base: /fr/
    locale: fr_FR.UTF-8
    navigationTitle: Francais
    flag: fr
    hreflang: fr-FR
    fallbackType: fallback
    fallbacks: '0'
    websiteTitle: ''
errorHandling: []
routes: {}
YAML;
        file_put_contents($path, $yaml);
        GeneralUtility::makeInstance(SiteConfiguration::class)->resolveAllExistingSites(false);
    }

    private function writeRootTemplate(int $rootPageUid, int $now): void
    {
        $config = <<<'TYPOSCRIPT'
page = PAGE
page {
  typeNum = 0

  cssInline.10 = TEXT
  cssInline.10.value (
    body{margin:0;background:#f4f7fb;color:#102033;font-family:"Segoe UI",Tahoma,sans-serif;line-height:1.6}.smoke-main{width:min(960px,calc(100% - 32px));margin:0 auto;padding:40px 0}.smoke-content{border:1px solid #d8e2ef;border-radius:16px;background:#fff;padding:36px;box-shadow:0 18px 50px rgba(15,31,55,.08)}h1{margin-top:0}a{color:#1d4ed8}
  )

  10 = COA
  10 {
    wrap = <main class="smoke-main"><section class="smoke-content">|</section></main>
    10 = TEXT
    10.field = title
    10.wrap = <h1>|</h1>
    20 < styles.content.get
  }
}
TYPOSCRIPT;

        $connection = $this->connectionPool->getConnectionForTable('sys_template');
        $connection->insert('sys_template', [
            'pid' => $rootPageUid,
            'crdate' => $now,
            'tstamp' => $now,
            'deleted' => 0,
            'hidden' => 0,
            'sorting' => $now,
            'title' => 'BT Smoke Root Template',
            'root' => 1,
            'clear' => 3,
            'include_static_file' => 'EXT:fluid_styled_content/Configuration/TypoScript/',
            'constants' => '',
            'config' => $config,
        ]);
    }

    private function upsertBackendUsers(): void
    {
        $hashFactory = GeneralUtility::makeInstance(PasswordHashFactory::class);
        $password = (string)$hashFactory->getDefaultHashInstance('BE')->getHashedPassword(self::PASSWORD);
        $connection = $this->connectionPool->getConnectionForTable('be_users');
        $now = time();
        foreach ([self::ADMIN_USERNAME => 1, self::LIMITED_USERNAME => 0] as $username => $admin) {
            $existing = $connection->select(['uid'], 'be_users', ['username' => $username, 'deleted' => 0])->fetchAssociative();
            $fields = [
                'pid' => 0,
                'tstamp' => $now,
                'username' => $username,
                'password' => $password,
                'admin' => $admin,
                'disable' => 0,
                'deleted' => 0,
                'lang' => 'en',
                'options' => 3,
            ];
            if (is_array($existing)) {
                $connection->update('be_users', $fields, ['uid' => (int)$existing['uid']]);
            } else {
                $fields['crdate'] = $now;
                $connection->insert('be_users', $fields);
            }
        }
    }

    private function insertPage(int $pid, string $title, string $slug, int $permsEveryBody, int $now, string $description = '', string $abstract = ''): int
    {
        $connection = $this->connectionPool->getConnectionForTable('pages');
        $connection->insert('pages', [
            'pid' => $pid,
            'crdate' => $now,
            'tstamp' => $now,
            'deleted' => 0,
            'hidden' => 0,
            'doktype' => 1,
            'sorting' => $now,
            'title' => $title,
            'slug' => $slug,
            'description' => $description !== '' ? $description : 'Deutscher Smoke Text fuer ' . $title,
            'abstract' => $abstract,
            'sys_language_uid' => 0,
            'l10n_parent' => 0,
            'perms_user' => 31,
            'perms_group' => 31,
            'perms_everybody' => $permsEveryBody,
        ]);

        return (int)$connection->lastInsertId('pages');
    }

    /**
     * @return array<string, int>
     */
    private function insertDefaultElements(int $pid, int $now): array
    {
        return [
            'e1' => $this->insertContent($pid, 'Willkommen', 'Ein kurzer deutscher Einstieg fuer die Seite mit ausreichend Kontext.', $now, 'text', 10),
            'e2' => $this->insertContent($pid, 'Einrichtung', 'Diese Einrichtung erklaert Produkt, Aufbau und naechste Schritte im Detail.', $now, 'text', 20),
            'e3' => $this->insertContent($pid, 'Redaktion', 'Redaktionelle Arbeit braucht klare Freigaben, Rollen und einen nachvollziehbaren Ablauf fuer laengere Texte mit mehr als einhundertfuenfzig Zeichen, damit die Vorschau gekuerzt werden kann.', $now, 'text', 30),
            'e4' => $this->insertContent($pid, 'Schneller Link', 'Schneller interner Link.', $now, 'text', 40),
        ];
    }

    private function insertContent(int $pid, string $header, string $bodytext, int $now, string $ctype, int $sorting): int
    {
        $connection = $this->connectionPool->getConnectionForTable('tt_content');
        $connection->insert('tt_content', [
            'pid' => $pid,
            'crdate' => $now,
            'tstamp' => $now,
            'deleted' => 0,
            'hidden' => 0,
            'sorting' => $sorting,
            'CType' => $ctype,
            'colPos' => 0,
            'header' => $header,
            'bodytext' => $bodytext,
            'sys_language_uid' => 0,
            'l18n_parent' => 0,
        ]);

        return (int)$connection->lastInsertId('tt_content');
    }

    private function insertTranslatedPage(int $sourceUid, string $title, string $description, int $now): int
    {
        $source = $this->connectionPool->getConnectionForTable('pages')->select(['pid', 'slug'], 'pages', ['uid' => $sourceUid])->fetchAssociative();
        $connection = $this->connectionPool->getConnectionForTable('pages');
        $connection->insert('pages', [
            'pid' => (int)($source['pid'] ?? 0),
            'crdate' => $now,
            'tstamp' => $now,
            'deleted' => 0,
            'hidden' => 0,
            'doktype' => 1,
            'title' => $title,
            'slug' => (string)($source['slug'] ?? '') . '-en',
            'description' => $description,
            'sys_language_uid' => self::TARGET_LANGUAGE_ID,
            'l10n_parent' => $sourceUid,
            'perms_user' => 31,
            'perms_group' => 31,
            'perms_everybody' => 256,
        ]);

        return (int)$connection->lastInsertId('pages');
    }

    private function insertTranslatedContent(int $pid, int $sourceUid, string $header, string $bodytext, int $now): int
    {
        $connection = $this->connectionPool->getConnectionForTable('tt_content');
        $connection->insert('tt_content', [
            'pid' => $pid,
            'crdate' => $now,
            'tstamp' => $now,
            'deleted' => 0,
            'hidden' => 0,
            'sorting' => 100,
            'CType' => 'text',
            'colPos' => 0,
            'header' => $header,
            'bodytext' => $bodytext,
            'sys_language_uid' => self::TARGET_LANGUAGE_ID,
            'l18n_parent' => $sourceUid,
        ]);

        return (int)$connection->lastInsertId('tt_content');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeJson(string $path, array $data): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}
