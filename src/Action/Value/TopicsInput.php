<?php

namespace Raneomik\NettePWA\Action\Value;

final readonly class TopicsInput implements \JsonSerializable
{
    private const int MaxTopics = 20;

    /**
     * @param non-empty-list<string> $values
     */
    private function __construct(
        public array $values,
    ) {
    }

    /**
     * @param false|string|string[] $topics
     */
    public static function fromInput(false|string|array $topics, callable $filter): self
    {
        if (false === $topics) {
            throw new \InvalidArgumentException("Missing input at 'topics'.");
        }

        if (is_string($topics)) {
            $topics = [$topics];
        }

        $topics = array_values(array_unique(array_filter($topics, $filter)));
        if ([] === $topics) {
            throw new \InvalidArgumentException('No topic you may subscribe to.');
        }

        if (self::MaxTopics < \count($topics)) {
            throw new \InvalidArgumentException(sprintf(
                '%d is too much topics. %d only are allowed.',
                \count($topics),
                self::MaxTopics,
            ));
        }

        return new self($topics);
    }

    /**
     * @return string[][]
     */
    public function jsonSerialize(): array
    {
        return [
            'topics' => $this->values,
        ];
    }
}
