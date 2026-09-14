<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Http;

use Recranet\DirectAdminBorg\Plugin;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Job status as JSON, for the UI's poller.
 *
 * Served from a .raw script, where the plugin owns the whole HTTP response
 * rather than having it wrapped in the DirectAdmin skin.
 */
final class StatusEndpoint
{
    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function handle(PluginRequest $request): Response
    {
        $job = $this->plugin->jobs()->find($request->param('job'));

        // At user level a job is visible only to the account that started it,
        // and an unauthorised job is reported as missing rather than forbidden
        // so the response cannot be used to probe for job ids.
        if ($job === null || (!$request->isAdmin() && $job->owner() !== $request->username)) {
            return $this->harden(new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND));
        }

        return $this->harden(new JsonResponse([
            'id'       => $job->id,
            'status'   => $job->status(),
            'badge'    => $job->badge(),
            'finished' => $job->isFinished(),
            'message'  => $job->message(),
            'log'      => $this->plugin->jobs()->tail($job, $request->isAdmin() ? 400 : 200),
        ]));
    }

    private function harden(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    /**
     * DirectAdmin expects a .raw script to print the whole HTTP response,
     * status line included, which is not what Response::send() emits.
     */
    public static function emit(Response $response): void
    {
        printf("HTTP/1.1 %d %s\r\n", $response->getStatusCode(), Response::$statusTexts[$response->getStatusCode()] ?? 'OK');

        foreach ($response->headers->allPreserveCaseWithoutCookies() as $name => $values) {
            foreach ($values as $value) {
                printf("%s: %s\r\n", $name, $value);
            }
        }

        echo "\r\n", $response->getContent();
    }
}
