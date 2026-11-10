<?php

declare(strict_types=1);

namespace Tests\Raneomik\NettePWA\Fixtures;

use Nette\Http\IResponse;

/**
 * Keeps the headers: in CLI, Nette\Http\Response sends them nowhere and getHeader() sees none.
 */
final class RecordingResponse implements IResponse
{
    /**
     * @var array<string, string>
     */
    public array $headers = [];

    public int $code = self::S200_OK;

    public function setCode(int $code, ?string $reason = null): static
    {
        $this->code = $code;

        return $this;
    }

    public function getCode(): int
    {
        return $this->code;
    }

    public function setHeader(string $name, string $value): static
    {
        $this->headers[$name] = $value;

        return $this;
    }

    public function addHeader(string $name, string $value): static
    {
        return $this->setHeader($name, $value);
    }

    public function setContentType(string $type, ?string $charset = null): static
    {
        return $this->setHeader('Content-Type', $type . (null === $charset ? '' : "; charset={$charset}"));
    }

    public function redirect(string $url, int $code = self::S302_Found): void
    {
        throw new \LogicException('not expected');
    }

    public function setExpiration(?string $expire): static
    {
        throw new \LogicException('not expected');
    }

    public function isSent(): bool
    {
        return false;
    }

    public function getHeader(string $header): ?string
    {
        return $this->headers[$header] ?? null;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function setCookie(
        string $name,
        string $value,
        string|int|\DateTimeInterface|null $expire,
        ?string $path = null,
        ?string $domain = null,
        ?bool $secure = null,
        ?bool $httpOnly = null,
    ): static {
        throw new \LogicException('not expected');
    }

    public function deleteCookie(string $name, ?string $path = null, ?string $domain = null, ?bool $secure = null): static
    {
        throw new \LogicException('not expected');
    }
}
