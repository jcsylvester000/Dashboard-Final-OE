<?php

namespace App\Http\Controllers\Integrations;

use App\Domain\Integrations\GitHubEvents;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessGitHubDelivery;
use App\Models\GitHubDelivery;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * E-24 (D-04): POST /webhooks/github. Verify the HMAC signature on the raw body,
 * record the delivery id (a replay or redelivery is acknowledged and ignored),
 * queue the work and answer 202 at once.
 */
class GitHubWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('services.github.webhook_secret', '');
        if ($secret === '') {
            return response()->json(['message' => 'GitHub webhooks are not configured.'], 503);
        }
        $body = $request->getContent();
        if (! GitHubEvents::validSignature($body, $request->headers->get('X-Hub-Signature-256'), $secret)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }
        $deliveryId = (string) $request->headers->get('X-GitHub-Delivery', '');
        $event = (string) $request->headers->get('X-GitHub-Event', '');
        if ($deliveryId === '' || $event === '' || strlen($deliveryId) > 64) {
            return response()->json(['message' => 'Missing delivery headers.'], 400);
        }
        $payload = json_decode($body, true);
        if (! is_array($payload)) {
            return response()->json(['message' => 'Body must be JSON (set the webhook content type to application/json).'], 400);
        }

        if (GitHubDelivery::query()->where('delivery_id', $deliveryId)->exists()) {
            return response()->json(['message' => 'Already received.'], 200);
        }
        try {
            $delivery = GitHubDelivery::query()->create([
                'delivery_id' => $deliveryId, 'event' => mb_substr($event, 0, 40),
                'repository' => mb_substr((string) data_get($payload, 'repository.full_name', ''), 0, 140) ?: null,
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['message' => 'Already received.'], 200); // two copies at once
        }
        ProcessGitHubDelivery::dispatch($delivery->id, $event, $payload);

        return response()->json(['message' => 'Queued.'], 202);
    }
}
