<?php

declare(strict_types=1);

namespace ContentStreamPlugin;

use App\Http\AcceptPrefersJson;
use PDO;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Shared GET/POST logic for the domain → OpenAI brief tool (public or admin UI).
 */
final class StreamToolHandler
{
    /**
     * @return array{
     *   domain: string,
     *   brief: string,
     *   analysis: array<string, mixed>|null,
     *   error: string,
     *   table_ok: bool,
     *   api_configured: bool
     * }
     */
    public static function processRequest(Request $request, PDO $pdo): array
    {
        $repo = new SettingsRepository($pdo);
        $settings = $repo->get();
        $parsed = $request->getParsedBody();
        $body = is_array($parsed) ? $parsed : [];
        $domain = '';
        $brief = '';
        $analysis = null;
        $error = '';

        if (strtoupper($request->getMethod()) === 'POST') {
            $domainRaw = isset($body['domain']) && is_string($body['domain']) ? $body['domain'] : '';
            $parsedDomain = DomainInput::parse($domainRaw);
            if ($parsedDomain === null) {
                $error = 'Enter a valid domain (e.g. example.com) without https://';
            } elseif (!$settings['api_key_stored']) {
                $error = 'OpenAI is not configured yet. Add an API key in Admin → Content Stream (Manage site settings).';
                $domain = $parsedDomain;
            } else {
                try {
                    $analysis = (new OpenAiBriefClient())->domainBusinessAnalysis(
                        $settings['openai_api_key'],
                        $settings['openai_organization'],
                        $settings['openai_model'],
                        $parsedDomain
                    );
                    $brief = json_encode(
                        $analysis,
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                    );
                    $domain = $parsedDomain;
                } catch (\Throwable $e) {
                    $error = $e->getMessage();
                    $domain = $parsedDomain;
                    $analysis = null;
                    $brief = '';
                }
            }
        }

        return [
            'domain' => $domain,
            'brief' => $brief,
            'analysis' => $analysis,
            'error' => $error,
            'table_ok' => $repo->tableExists(),
            'api_configured' => $settings['api_key_stored'],
        ];
    }

    public static function wantsJsonResponse(Request $request): bool
    {
        if (strtoupper($request->getMethod()) !== 'POST') {
            return false;
        }

        return AcceptPrefersJson::withoutHtml($request);
    }
}
