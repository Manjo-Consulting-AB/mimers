<?php

namespace App\Support\Help;

/**
 * En kategori ur `categories.yaml` — M80 · issue 460,
 * [[ADR-0094 Hjälpcentret]] § 1.
 *
 * Ordningen är filens: `order` är postens index i `categories.yaml`, och den
 * ordningen är ordningen på sidan (ADR-0094 § 1). Klassen bär ingen regel —
 * id:n, titlar och beskrivningar prövas av App\Support\Help\HelpValidator.
 *
 * `intro` är markdown och valfri; `description` är en mening för kategorikortet.
 */
final readonly class HelpCategory
{
    /**
     * @param  string  $description  En mening för kategorikortet.
     * @param  ?string  $intro  Valfri ingress i markdown.
     * @param  int  $order  Postens index i `categories.yaml`.
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $description,
        public ?string $intro,
        public int $order,
    ) {}

    /**
     * Kategorin som bara arrayer och skalärer — det som går i cachen.
     *
     * @return array{
     *     id: string,
     *     title: string,
     *     description: string,
     *     intro: ?string,
     *     order: int,
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'intro' => $this->intro,
            'order' => $this->order,
        ];
    }

    /**
     * Bygger tillbaka kategorin ur `toArray()`-formen.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            title: (string) $data['title'],
            description: (string) $data['description'],
            intro: isset($data['intro']) ? (string) $data['intro'] : null,
            order: (int) $data['order'],
        );
    }
}
