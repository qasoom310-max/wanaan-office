<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use LogicException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * A file attached to a chat message: a picture, a PDF, a spreadsheet or a text file.
 * Never changed or deleted, like the message it belongs to.
 */
#[Fillable(['message_id', 'name', 'path', 'mime', 'size'])]
class MessageFile extends Model
{
    public const UPDATED_AT = null;

    /** What can be attached, and the most each may weigh. */
    public const ACCEPT = 'jpg,jpeg,png,gif,webp,pdf,csv,txt,xlsx,xls';

    public const MAX_KB = 10240;

    public const PER_MESSAGE = 5;

    /** Characters of a spreadsheet or text file passed to an agent at most. */
    private const MAX_TEXT = 150000;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Chat files are never changed.'));
        static::deleting(fn (): never => throw new LogicException('Chat files are never deleted.'));
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function isImage(): bool
    {
        return in_array($this->mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);
    }

    public function isPdf(): bool
    {
        return $this->mime === 'application/pdf';
    }

    public function isSpreadsheet(): bool
    {
        return in_array(strtolower(pathinfo($this->name, PATHINFO_EXTENSION)), ['xlsx', 'xls'], true);
    }

    /**
     * The file as the agent reads it: a picture or PDF as itself, anything else as text.
     *
     * @return list<array<string, mixed>>
     */
    public function contentBlocks(): array
    {
        $label = ['type' => 'text', 'text' => "[Attached file: {$this->name}]"];
        $raw = Storage::disk('local')->get($this->path);
        if ($raw === null) {
            return [['type' => 'text', 'text' => "[Attached file {$this->name} could not be found.]"]];
        }

        if ($this->isImage()) {
            return [$label, ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $this->mime, 'data' => base64_encode($raw)]]];
        }
        if ($this->isPdf()) {
            return [$label, ['type' => 'document', 'source' => ['type' => 'base64', 'mediaType' => 'application/pdf', 'data' => base64_encode($raw)], 'title' => $this->name]];
        }

        $text = $this->isSpreadsheet() ? $this->spreadsheetAsText() : $raw;
        if (mb_strlen($text) > self::MAX_TEXT) {
            $text = mb_substr($text, 0, self::MAX_TEXT)."\n[cut short: the file is longer than this]";
        }

        return [['type' => 'text', 'text' => "[Attached file: {$this->name}]\n".$text]];
    }

    /** Every sheet of a spreadsheet as tab-separated rows. */
    private function spreadsheetAsText(): string
    {
        try {
            $book = IOFactory::load(Storage::disk('local')->path($this->path));
        } catch (Throwable) {
            return '[The spreadsheet could not be read.]';
        }

        $out = [];
        foreach ($book->getWorksheetIterator() as $sheet) {
            $out[] = '## Sheet: '.$sheet->getTitle();
            foreach ($sheet->toArray(null, true, true, false) as $row) {
                if (array_filter($row, fn ($cell): bool => $cell !== null && $cell !== '') !== []) {
                    $out[] = implode("\t", array_map(fn ($cell): string => (string) $cell, $row));
                }
            }
        }

        return implode("\n", $out);
    }
}
