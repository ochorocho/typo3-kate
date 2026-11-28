<?php

declare(strict_types=1);

namespace Ochorocho\KateDemoChat\Chat;

/**
 * One chat message as it goes to the Mercure hub. The text comes from the
 * browser; who wrote it comes from the backend login, never from the client.
 */
final readonly class ChatMessage
{
    public const int MAX_LENGTH = 1000;

    private function __construct(
        public string $id,
        public int $userId,
        public string $userName,
        public string $text,
        public \DateTimeImmutable $time,
    ) {}

    /**
     * @throws \InvalidArgumentException when the text is empty, too long or not UTF-8
     */
    public static function create(string $text, int $userId, string $userName, \DateTimeImmutable $time): self
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            throw new \InvalidArgumentException('The message is not valid UTF-8.', 1764316800);
        }
        // Control characters out, line breaks and tabs stay
        $text = trim((string)preg_replace('/[^\P{Cc}\n\t]/u', '', $text));
        if ($text === '') {
            throw new \InvalidArgumentException('The message is empty.', 1764316801);
        }
        if (mb_strlen($text) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException(sprintf('The message is longer than %d characters.', self::MAX_LENGTH), 1764316802);
        }
        return new self(bin2hex(random_bytes(8)), $userId, $userName, $text, $time);
    }

    public function toJson(): string
    {
        return json_encode([
            'id' => $this->id,
            'user' => ['id' => $this->userId, 'name' => $this->userName],
            'text' => $this->text,
            'time' => $this->time->format(\DateTimeInterface::ATOM),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
