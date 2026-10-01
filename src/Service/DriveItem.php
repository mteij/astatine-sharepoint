<?php
declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use DateTimeZone;

final class DriveItem
{
    /**
     * Extension => [kind, label]. The kind picks the icon colour, the label is shown in the Type column.
     */
    private const TYPES = [
        'pdf' => ['pdf', 'PDF document'],
        'doc' => ['word', 'Word document'], 'docx' => ['word', 'Word document'], 'odt' => ['word', 'Text document'],
        'xls' => ['excel', 'Excel spreadsheet'], 'xlsx' => ['excel', 'Excel spreadsheet'], 'xlsm' => ['excel', 'Excel spreadsheet'],
        'ods' => ['excel', 'Spreadsheet'], 'csv' => ['excel', 'CSV file'],
        'ppt' => ['powerpoint', 'PowerPoint presentation'], 'pptx' => ['powerpoint', 'PowerPoint presentation'],
        'odp' => ['powerpoint', 'Presentation'],
        'one' => ['onenote', 'OneNote notebook'],
        'png' => ['image', 'PNG image'], 'jpg' => ['image', 'JPEG image'], 'jpeg' => ['image', 'JPEG image'],
        'gif' => ['image', 'GIF image'], 'svg' => ['image', 'SVG image'], 'webp' => ['image', 'WebP image'],
        'heic' => ['image', 'HEIC image'], 'bmp' => ['image', 'Bitmap image'],
        'zip' => ['archive', 'ZIP archive'], '7z' => ['archive', '7-Zip archive'], 'rar' => ['archive', 'RAR archive'],
        'gz' => ['archive', 'GZ archive'], 'tar' => ['archive', 'TAR archive'],
        'mp3' => ['audio', 'MP3 audio'], 'wav' => ['audio', 'WAV audio'], 'm4a' => ['audio', 'Audio'],
        'mp4' => ['video', 'MP4 video'], 'mov' => ['video', 'Video'], 'mkv' => ['video', 'Video'], 'avi' => ['video', 'Video'],
        'txt' => ['text', 'Text file'], 'md' => ['text', 'Markdown file'], 'rtf' => ['text', 'Rich text'],
        'html' => ['text', 'HTML file'], 'json' => ['text', 'JSON file'], 'xml' => ['text', 'XML file'],
    ];

    public function __construct(
        public readonly string $name,
        public readonly bool $isFolder,
        public readonly ?int $size,
        public readonly ?DateTimeImmutable $modified,
        public readonly string $webUrl,
        public readonly ?int $childCount = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data A Graph driveItem.
     */
    public static function fromGraph(array $data): self
    {
        $modified = isset($data['lastModifiedDateTime'])
            ? (new DateTimeImmutable($data['lastModifiedDateTime']))
                ->setTimezone(new DateTimeZone(date_default_timezone_get()))
            : null;
        $childCount = is_array($data['folder'] ?? null) && isset($data['folder']['childCount'])
            ? (int)$data['folder']['childCount']
            : null;

        return new self(
            (string)$data['name'],
            isset($data['folder']),
            isset($data['size']) ? (int)$data['size'] : null,
            $modified,
            (string)($data['webUrl'] ?? ''),
            $childCount,
        );
    }

    /**
     * Icon family: folder, pdf, word, excel, powerpoint, onenote, image, archive, audio, video, text or file.
     */
    public function kind(): string
    {
        return $this->isFolder ? 'folder' : (self::TYPES[$this->extension()][0] ?? 'file');
    }

    /**
     * Explorer-style description for the Type column, e.g. "File folder" or "PDF document".
     */
    public function typeLabel(): string
    {
        if ($this->isFolder) {
            return 'File folder';
        }
        $extension = $this->extension();

        return self::TYPES[$extension][1] ?? ($extension === '' ? 'File' : strtoupper($extension) . ' file');
    }

    public function extension(): string
    {
        $dot = strrpos($this->name, '.');

        return $dot === false || $dot === 0 ? '' : strtolower(substr($this->name, $dot + 1));
    }
}
