<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend\Message;

/**
 * An error answer: `{message, code}`, or the body of an API whose clients read errors of another shape.
 *
 * @internal
 */
final class Error implements \JsonSerializable, HttpCode
{
    /**
     * @param array<non-empty-string, mixed>|null $body
     */
    public function __construct(
        public readonly string $message,
        public readonly int $code = 404,
        private readonly ?array $body = null,
    ) {}

    public static function notFound(string $message = 'Not found'): self
    {
        return new self($message, 404);
    }

    /**
     * An error of the Xdebug pages, as `{error, code: "not_paused", state}`.
     *
     * @param array{error: string}&array<non-empty-string, mixed> $body
     */
    public static function withBody(int $code, array $body): self
    {
        return new self($body['error'], $code, $body);
    }

    #[\Override]
    public function httpCode(): int
    {
        return $this->code;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->body ?? [
            'message' => $this->message,
            'code' => $this->code,
        ];
    }
}
