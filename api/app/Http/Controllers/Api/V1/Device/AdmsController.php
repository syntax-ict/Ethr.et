<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Tenant;
use App\Services\CurrentTenant;
use App\Services\Device\AdmsDeviceResolver;
use App\Services\Device\AdmsProtocol;
use App\Services\Device\AdmsRoster;
use App\Services\Device\DeviceCommandQueue;
use App\Services\Device\DevicePunchRecorder;
use App\Services\Device\ZktecoAdapter;
use Dedoc\Scramble\Attributes\ExcludeRouteFromDocs;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * ZKTeco ADMS: the terminal calls ETHR, so it works from a customer's LAN to
 * shared hosting, where ETHR cannot call it. Every request is identified by
 * `?SN=` and authenticated by AdmsDeviceResolver:
 *
 * - `GET  /iclock/cdata`       start-up: ETHR answers with its options
 * - `POST /iclock/cdata`       punches (`table=ATTLOG`), and user records for
 *                               the roster (AdmsRoster); other logs are ignored
 * - `GET  /iclock/getrequest`  "anything for me?": the waiting commands
 * - `POST /iclock/devicecmd`   what became of them
 * - `POST /iclock/querydata`   PUSH 3.x: the answer to a roster request
 *
 * Every answer is plain text. A punch ETHR cannot use — an unknown PIN, a
 * time in the future — is still acknowledged in the count: refusing it would
 * make the device send it again forever.
 *
 * Outside `/api/v1` because the path is fixed in the firmware; outside the
 * `api` middleware group because a device has no session, no CSRF token and
 * no tenant host. Its own limiter is `adms`.
 */
class AdmsController extends Controller
{
    public function __construct(
        private readonly AdmsDeviceResolver $resolver,
        private readonly DeviceCommandQueue $commands,
        private readonly DevicePunchRecorder $punches,
        private readonly AdmsRoster $roster,
    ) {}

    #[ExcludeRouteFromDocs]
    public function handshake(Request $request): Response
    {
        $device = $this->device($request);
        if ($device === null) {
            return $this->text('Unknown device', 401);
        }

        // A device ETHR holds no roster for is asked at start-up, so the list
        // is usually there by the time anyone opens it.
        if ($this->roster->wantsRequest($device)) {
            $this->commands->requestEnrollments($device);
        }

        return $this->text(AdmsProtocol::handshake($device));
    }

    #[ExcludeRouteFromDocs]
    public function upload(Request $request): Response
    {
        $device = $this->device($request);
        if ($device === null) {
            return $this->text('Unknown device', 401);
        }

        $body = $request->getContent();
        $table = strtoupper((string) $request->query('table'));

        if ($table !== 'ATTLOG') {
            // People in operation logs and user records go to the roster; the
            // rest (log entries, templates, photos) is acknowledged, not stored.
            if ($table !== 'ATTPHOTO') {
                $this->roster->record($device, AdmsProtocol::userLines($body));
            }

            return $this->text('OK: '.$this->lineCount($body));
        }

        $punches = AdmsProtocol::attendanceLines($body);
        foreach ($punches as $punch) {
            $this->punches->recordBatched(
                $device,
                'adms',
                $punch['pin'],
                $punch['time'],
                ZktecoAdapter::directionFor($punch['status']),
            );
        }

        return $this->text('OK: '.count($punches));
    }

    #[ExcludeRouteFromDocs]
    public function getRequest(Request $request): Response
    {
        $device = $this->device($request);
        if ($device === null) {
            return $this->text('Unknown device', 401);
        }

        if ($device->commands_enabled) {
            $this->commands->setTimeIfDue($device);
        }

        $lines = $this->commands->collect($device)
            ->map(fn ($command) => AdmsProtocol::command($device, $command))
            ->all();

        return $this->text($lines === [] ? 'OK' : implode("\n", $lines)."\n");
    }

    #[ExcludeRouteFromDocs]
    public function commandResult(Request $request): Response
    {
        $device = $this->device($request);
        if ($device === null) {
            return $this->text('Unknown device', 401);
        }

        foreach (AdmsProtocol::commandResults($request->getContent()) as $result) {
            $this->commands->acknowledge($device, $result['id'], $result['return']);
        }

        return $this->text('OK');
    }

    /**
     * PUSH 3.x firmware answers a roster request here rather than in
     * `cdata`: `?tablename=user&count=..&packcnt=..&packidx=..`, one person
     * per line. The answer is `<tablename>=<count>`, the records received.
     */
    #[ExcludeRouteFromDocs]
    public function queryData(Request $request): Response
    {
        $device = $this->device($request);
        if ($device === null) {
            return $this->text('Unknown device', 401);
        }

        $body = $request->getContent();
        $table = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $request->query('tablename', 'user'))) ?: 'user';

        $this->roster->record($device, AdmsProtocol::userLines($body));

        return $this->text($table.'='.$this->lineCount($body));
    }

    #[ExcludeRouteFromDocs]
    public function ping(Request $request): Response
    {
        return $this->device($request) === null ? $this->text('Unknown device', 401) : $this->text('OK');
    }

    /**
     * The authenticated device, with its tenant made current — the engine and
     * the identity lookups run through the tenant scope — and its last contact
     * stamped, which is what keeps it "online".
     */
    private function device(Request $request): ?Device
    {
        $device = $this->resolver->resolve($request->query('SN'), $request->ip());
        if ($device === null) {
            return null;
        }

        // A deleted organisation's device is unknown here: its punches have
        // no organisation to belong to.
        $tenant = Tenant::query()->find($device->tenant_id);
        if ($tenant === null) {
            return null;
        }
        app(CurrentTenant::class)->set($tenant);

        $device->forceFill(['last_sync_at' => now(), 'status' => 'online'])->save();

        return $device;
    }

    /** Every non-blank line, used or not: a count short of what was sent makes the device send it again. */
    private function lineCount(string $body): int
    {
        return count(array_filter(preg_split('/\r\n|\n|\r/', $body) ?: [], fn ($l) => trim($l) !== ''));
    }

    private function text(string $body, int $status = 200): Response
    {
        return response($body, $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
