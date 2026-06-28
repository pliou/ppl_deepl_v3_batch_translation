<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Service;

use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Shared guard for the default-language page scans used by the batch selection/preview services.
 *
 * The scans are capped so a very large page tree cannot exhaust memory, but a SILENT cap makes the
 * selection, the preview and the actually-executed scope diverge without anyone noticing. The
 * services therefore fetch one row beyond their limit and pass the result through capScannedPages(),
 * which detects the overflow precisely, emits a visible warning to the log, and returns the rows
 * trimmed back to the limit (so the cap still protects memory, but truncation is no longer silent).
 */
trait PageScanLimitTrait
{
    /**
     * @param array<int, array<string, mixed>> $rows rows fetched with setMaxResults($limit + 1)
     * @return array<int, array<string, mixed>> rows trimmed to at most $limit
     */
    private function capScannedPages(array $rows, int $limit): array
    {
        if (count($rows) <= $limit) {
            return $rows;
        }

        GeneralUtility::makeInstance(LogManager::class)
            ->getLogger(static::class)
            ->warning(
                'Batch page scan hit the safety limit; the page list is truncated and may not cover every page.',
                ['limit' => $limit]
            );

        return array_slice($rows, 0, $limit);
    }
}
