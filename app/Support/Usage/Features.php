<?php

namespace App\Support\Usage;

/**
 * Namnen på de funktioner som räknas i den anonyma mätningen
 * ([[ADR-0079 Övervakning]] § 4, issue 391). Konstanter, så att de inte
 * sprids som fria strängar; App\Actions\Usage\CountFeatureUse tar bara emot
 * ett namn härifrån.
 */
final class Features
{
    public const TASK_QUICK_CREATE = 'task.quick_create';

    public const TASK_FORM_CREATE = 'task.form_create';

    public const ATTACHMENT_UPLOAD = 'attachment.upload';

    public const ATTACHMENT_MOVE = 'attachment.move';

    public const ATTACHMENT_COPY = 'attachment.copy';

    public const ATTACHMENT_TO_INBOX = 'attachment.to_inbox';

    public const COST_CREATE = 'cost.create';

    public const ITEM_INCLUDE_CHILDREN_OFF = 'item.include_children_off';

    public const ITEM_CREATE_CHILD = 'item.create_child';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::TASK_QUICK_CREATE,
            self::TASK_FORM_CREATE,
            self::ATTACHMENT_UPLOAD,
            self::ATTACHMENT_MOVE,
            self::ATTACHMENT_COPY,
            self::ATTACHMENT_TO_INBOX,
            self::COST_CREATE,
            self::ITEM_INCLUDE_CHILDREN_OFF,
            self::ITEM_CREATE_CHILD,
        ];
    }

    /**
     * Snabbraden skickar bara titel, återkomsttyp och lista; formuläret skickar
     * fler fält. Skillnaden läses ur de validerade nycklarna, eftersom ytan
     * (resources/**) inte bär någon egen markör.
     *
     * @param  list<string>  $validatedKeys
     */
    public static function forTaskCreate(array $validatedKeys): string
    {
        $extra = array_diff($validatedKeys, ['title', 'recurrence_type', 'gtd_list']);

        return $extra === [] ? self::TASK_QUICK_CREATE : self::TASK_FORM_CREATE;
    }
}
