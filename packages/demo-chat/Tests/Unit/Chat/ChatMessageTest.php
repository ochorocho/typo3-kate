<?php

declare(strict_types=1);

namespace Ochorocho\KateDemoChat\Tests\Unit\Chat;

use Ochorocho\KateDemoChat\Chat\ChatMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChatMessageTest extends TestCase
{
    private function create(string $text): ChatMessage
    {
        return ChatMessage::create($text, 7, 'Jo', new \DateTimeImmutable('2026-11-28T10:15:00+00:00'));
    }

    #[Test]
    public function jsonHasIdUserTextAndTime(): void
    {
        $message = $this->create('Hello');

        self::assertSame([
            'id' => $message->id,
            'user' => ['id' => 7, 'name' => 'Jo'],
            'text' => 'Hello',
            'time' => '2026-11-28T10:15:00+00:00',
        ], json_decode($message->toJson(), true));
    }

    #[Test]
    public function textIsTrimmedAndKeepsLineBreaks(): void
    {
        self::assertSame("one\ntwo", $this->create("  one\ntwo \n")->text);
    }

    #[Test]
    public function controlCharactersAreRemoved(): void
    {
        self::assertSame('ab', $this->create("a\x00\x1Bb")->text);
    }

    #[Test]
    public function idsDiffer(): void
    {
        self::assertNotSame($this->create('a')->id, $this->create('a')->id);
    }

    #[Test]
    public function textOfMaximumLengthIsAccepted(): void
    {
        self::assertSame(ChatMessage::MAX_LENGTH, mb_strlen($this->create(str_repeat('ä', ChatMessage::MAX_LENGTH))->text));
    }

    /** @return array<string, array{string}> */
    public static function invalidTexts(): array
    {
        return [
            'empty' => [''],
            'only whitespace' => [" \n\t "],
            'only control characters' => ["\x00\x07"],
            'too long' => [str_repeat('a', ChatMessage::MAX_LENGTH + 1)],
            'not UTF-8' => ["\xC3\x28"],
        ];
    }

    #[Test]
    #[DataProvider('invalidTexts')]
    public function invalidTextIsRefused(string $text): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->create($text);
    }
}
