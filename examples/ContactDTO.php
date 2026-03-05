<?php

namespace App\DTOs;

/**
 * Represents a single Contact row from SeaTable.
 * Using a DTO prevents the application from depending on raw SeaTable arrays
 * and provides strict typing for all properties.
 */
class ContactDTO
{
    public readonly string $id;
    public readonly string $name;
    public readonly string $surname;
    public readonly string $phoneNumber;

    private function __construct(
        string $id,
        string $name,
        string $surname,
        string $phoneNumber
    ) {
        $this->id          = $id;
        $this->name        = $name;
        $this->surname     = $surname;
        $this->phoneNumber = $phoneNumber;
    }

    /**
     * Factory method to map a raw SeaTable array (from querySQL with convert_keys=true)
     * into a strongly typed DTO.
     *
     * @param array<string, mixed> $data Row from SeaTable querySQL results
     */
    public static function fromSeaTableArray(array $data): self
    {
        return new self(
            id:          (string) ($data['_id'] ?? ''),
            name:        (string) ($data['name'] ?? ''),
            surname:     (string) ($data['surname'] ?? ''),
            // Map column names with spaces exactly as they appear in the SeaTable base
            phoneNumber: (string) ($data['phone number'] ?? ''),
        );
    }

    /**
     * Convert DTO to a plain array suitable for JSON responses.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'surname'      => $this->surname,
            'phone_number' => $this->phoneNumber,
        ];
    }
}
