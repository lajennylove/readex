<?php

namespace App\Refresh;

use App\MangaDex\Exceptions\ApiError;
use App\MangaDex\Exceptions\RequestDeferred;
use App\MangaDex\RateLimit\BusyReason;
use App\Storage\StorageBridge;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * How a refresh ended, as plain values: an AsyncTask's failed() callback only gets the
 * exception's class and message, so the retry time would be lost if the exception crossed.
 */
final readonly class Outcome
{
    private function __construct(
        public RefreshStatus $status,
        public ?float $retryAt = null,
        public mixed $result = null,
    ) {}

    public static function capture(Closure $work): self
    {
        try {
            return new self(RefreshStatus::Ok, result: $work());
        } catch (RequestDeferred $e) {
            return new self(
                $e->busy->reason === BusyReason::Saturated ? RefreshStatus::Busy : RefreshStatus::Limited,
                $e->busy->retryAt,
            );
        } catch (ConnectionException) {
            return new self(RefreshStatus::Offline);
        } catch (Throwable $e) {
            if ($e instanceof ApiError && $e->status >= 500 && $e->status <= 599) {
                return new self(RefreshStatus::Unavailable);
            }

            report($e);

            return new self(RefreshStatus::Failed);
        }
    }

    public static function failed(): self
    {
        return new self(RefreshStatus::Failed);
    }

    /** @return array{status: string, retry_at: float|null, result: mixed} */
    public function toArray(): array
    {
        return ['status' => $this->status->value, 'retry_at' => $this->retryAt, 'result' => $this->result];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            RefreshStatus::tryFrom($data['status'] ?? '') ?? RefreshStatus::Failed,
            isset($data['retry_at']) ? (float) $data['retry_at'] : null,
            $data['result'] ?? null,
        );
    }

    /** Spec §13 wording; null when nothing needs saying. */
    public function statusLine(): ?string
    {
        return match ($this->status) {
            RefreshStatus::Ok => null,
            RefreshStatus::Offline => 'Offline, showing saved data',
            RefreshStatus::Busy => 'The provider is busy, will update shortly',
            RefreshStatus::Limited => 'The provider is limiting this phone, try after '.$this->localTime(),
            RefreshStatus::Unavailable => 'The provider is having trouble, retrying',
            RefreshStatus::Failed => 'Something went wrong',
        };
    }

    /** The app runs in UTC; the line shows the phone's clock. */
    private function localTime(): string
    {
        return CarbonImmutable::createFromTimestamp((int) ceil($this->retryAt ?? 0))
            ->setTimezone(app(StorageBridge::class)->timeZone())
            ->format('H:i');
    }
}
