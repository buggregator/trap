<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Csp;

use Buggregator\Trap\Support\Json;

/**
 * Reads the two formats browsers send security reports in into one report shape, the one of
 * buggregator/server that the web UI shows:
 *
 * - `application/csp-report`: `{"csp-report": {...}}`, one CSP violation of a `report-uri` directive;
 * - `application/reports+json`: a list of Reporting API reports of a `report-to` directive or the
 *   `Reporting-Endpoints` header: `csp-violation`, `deprecation`, `intervention`, `crash`, ...
 *
 * Empty fields are left out of a report; `type`, `url` and `body`, the report as the browser sent it, are always there.
 *
 * @internal
 * @psalm-internal Buggregator\Trap
 *
 * @psalm-type CspReport = array{
 *     type: string,
 *     url: string,
 *     user_agent?: non-empty-string,
 *     directive?: non-empty-string,
 *     blocked?: non-empty-string,
 *     disposition?: non-empty-string,
 *     source_file?: non-empty-string,
 *     line?: positive-int,
 *     column?: positive-int,
 *     sample?: non-empty-string,
 *     policy?: non-empty-string,
 *     message?: non-empty-string,
 *     id?: non-empty-string,
 *     body: array|\stdClass,
 * }
 */
final class Report
{
    public const FORMAT_CSP = 'csp-report';
    public const FORMAT_REPORTS = 'reports+json';
    public const TYPE_CSP_VIOLATION = 'csp-violation';

    /**
     * @return self::FORMAT_*|null Null for another media type.
     */
    public static function formatOf(string $contentType): ?string
    {
        return match (\strtolower(\trim(\explode(';', $contentType, 2)[0]))) {
            'application/csp-report' => self::FORMAT_CSP,
            'application/reports+json' => self::FORMAT_REPORTS,
            default => null,
        };
    }

    /**
     * @param self::FORMAT_*|null $format Null to guess by the body: a list is `reports+json`, an object `csp-report`.
     *
     * @return array{self::FORMAT_*, non-empty-list<CspReport>}
     *
     * @throws \InvalidArgumentException When the body is not a document of the format.
     */
    public static function parse(?string $format, string $body): array
    {
        $format ??= \str_starts_with(\ltrim($body), '[') ? self::FORMAT_REPORTS : self::FORMAT_CSP;

        try {
            /** @var mixed $data */
            $data = Json::finite(Json::decode($body));
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException("Not a $format document: {$e->getMessage()}", previous: $e);
        }

        return [$format, $format === self::FORMAT_CSP ? [self::cspReport($data)] : self::reports($data)];
    }

    /**
     * A report as {@see parse()} made it, from its JSON: the frames come back from files and other Trap servers.
     *
     * @return CspReport
     */
    public static function restore(mixed $report): array
    {
        \is_array($report) or $report = [];
        /** @var mixed $body */
        $body = $report['body'] ?? null;

        return self::make(
            [
                'type' => $report['type'] ?? null,
                'url' => $report['url'] ?? null,
                'user_agent' => $report['user_agent'] ?? null,
                'directive' => $report['directive'] ?? null,
                'blocked' => $report['blocked'] ?? null,
                'disposition' => $report['disposition'] ?? null,
                'source_file' => $report['source_file'] ?? null,
                'line' => $report['line'] ?? null,
                'column' => $report['column'] ?? null,
                'sample' => $report['sample'] ?? null,
                'policy' => $report['policy'] ?? null,
                'message' => $report['message'] ?? null,
                'id' => $report['id'] ?? null,
            ],
            \is_array($body) ? $body : [],
        );
    }

    /**
     * Whether the report is a CSP violation, of either format.
     *
     * @param CspReport $report
     */
    public static function isViolation(array $report): bool
    {
        return $report['type'] === self::TYPE_CSP_VIOLATION || isset($report['directive']);
    }

    /**
     * The file of the report with its line and column, as `file:line:column`.
     *
     * @param CspReport $report
     */
    public static function source(array $report): string
    {
        $file = $report['source_file'] ?? '';
        if ($file === '') {
            return '';
        }

        $position = \implode(':', \array_filter([$report['line'] ?? 0, $report['column'] ?? 0]));

        return $position === '' ? $file : "$file:$position";
    }

    /**
     * @return CspReport
     */
    private static function cspReport(mixed $data): array
    {
        /** @var mixed $report */
        $report = \is_array($data) ? $data['csp-report'] ?? null : null;
        \is_array($report) && $report !== [] && !\array_is_list($report)
            or throw new \InvalidArgumentException('Not a csp-report document: no `csp-report` object.');

        /** @var mixed $directive */
        $directive = $report['effective-directive'] ?? null;
        if (!\is_string($directive) || $directive === '') {
            /** @var mixed $directive */
            $directive = $report['violated-directive'] ?? null;
        }

        return self::make([
            'type' => self::TYPE_CSP_VIOLATION,
            'url' => $report['document-uri'] ?? null,
            'directive' => $directive,
            'blocked' => $report['blocked-uri'] ?? null,
            'disposition' => $report['disposition'] ?? null,
            'source_file' => $report['source-file'] ?? null,
            'line' => $report['line-number'] ?? null,
            'column' => $report['column-number'] ?? null,
            'sample' => $report['script-sample'] ?? null,
            'policy' => $report['original-policy'] ?? null,
        ], $report);
    }

    /**
     * @return non-empty-list<CspReport>
     */
    private static function reports(mixed $data): array
    {
        \is_array($data) && \array_is_list($data)
            or throw new \InvalidArgumentException('Not a reports+json document: no list of reports.');
        $data === [] and throw new \InvalidArgumentException('Not a reports+json document: the list of reports is empty.');

        $reports = [];
        /** @var mixed $item */
        foreach ($data as $item) {
            \is_array($item) && !\array_is_list($item)
                or throw new \InvalidArgumentException('Not a reports+json document: a report is not an object.');

            /** @var mixed $body */
            $body = $item['body'] ?? null;
            $body = \is_array($body) ? $body : [];
            $reports[] = self::make([
                'type' => $item['type'] ?? null,
                'url' => $item['url'] ?? null,
                'user_agent' => $item['user_agent'] ?? null,
                'directive' => $body['effectiveDirective'] ?? null,
                'blocked' => $body['blockedURL'] ?? null,
                'disposition' => $body['disposition'] ?? null,
                'source_file' => $body['sourceFile'] ?? null,
                'line' => $body['lineNumber'] ?? null,
                'column' => $body['columnNumber'] ?? null,
                'sample' => $body['sample'] ?? null,
                'policy' => $body['originalPolicy'] ?? null,
                'message' => $body['message'] ?? null,
                'id' => $body['id'] ?? null,
            ], $body);
        }

        return $reports;
    }

    /**
     * @param array<non-empty-string, mixed> $fields A field of a wrong type counts as empty.
     *
     * @return CspReport
     */
    private static function make(array $fields, array $body): array
    {
        $report = ['type' => '', 'url' => ''];
        /** @var mixed $value */
        foreach ($fields as $name => $value) {
            if (\in_array($name, ['line', 'column'], true)) {
                $value = \is_int($value) || \is_string($value) && \ctype_digit($value) ? (int) $value : 0;
                $value > 0 and $report[$name] = $value;
                continue;
            }

            $value = \is_string($value) ? $value : '';
            ($value !== '' || isset($report[$name])) and $report[$name] = $value;
        }
        // An empty body is an object, as the browser sent it
        $report['body'] = $body === [] ? new \stdClass() : $body;

        /** @var CspReport */
        return $report;
    }
}
