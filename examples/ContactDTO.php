<?php

namespace App\DTOs;

/**
 * Represents a single Contact row from SeaTable.
 * Using a DTO prevents the application from depending on raw SeaTable arrays
 * and provides strict typing for all properties.
 */
class ContactDTO
{
    public string $id;
    public string $name;
    public string $surname;
    public string $phoneNumber;

    /**
     * Factory method to map a raw SeaTable array (from querySQL with convert_keys=true)
     * into a strongly typed DTO.
     */
    public static function fromSeaTableArray(array $data): self
    {
        $dto = new self();

        // SeaTable always includes the internal row ID as '_id'
        $dto->id          = $data['_id'] ?? '';

        $dto->name        = $data['name'] ?? '';
        $dto->surname     = $data['surname'] ?? '';

        // Note: Map column names with spaces exactly as they appear in the database
        $dto->phoneNumber = $data['phone number'] ?? '';

        return $dto;
    }
}