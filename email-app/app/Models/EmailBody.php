<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Email body content stored separately from email metadata.
 *
 * @property string $id
 * @property string $email_id
 * @property string|null $html_body
 * @property string|null $text_body
 * @property string|null $body_hash
 * @property string|null $compression
 * @property string $storage_driver
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Email $email
 */
class EmailBody extends BaseModel
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'email_bodies';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'email_id',
        'html_body',
        'text_body',
        'body_hash',
        'compression',
        'storage_driver',
        'metadata',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'metadata' => 'array',
        ]);
    }

    /**
     * Get the email that owns the body content.
     */
    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }

    /**
     * Determine whether an HTML body is present.
     */
    public function hasHtmlBody(): bool
    {
        return $this->html_body !== null && $this->html_body !== '';
    }

    /**
     * Determine whether a plain text body is present.
     */
    public function hasTextBody(): bool
    {
        return $this->text_body !== null && $this->text_body !== '';
    }

    /**
     * Determine whether the body content is compressed.
     */
    public function isCompressed(): bool
    {
        return $this->compression !== null && $this->compression !== '';
    }
}
