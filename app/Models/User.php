<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'username', 'email', 'password', 'profile_photo_path', 'last_seen_at', 'last_login_at', 'last_login_ip', 'disabled_at', 'must_change_password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, SoftDeletes;

    /**
     * Presence rules for the User Management "Status" column. A request writes
     * last_seen_at at most once per PRESENCE_WRITE_EVERY seconds (so the users
     * table is not updated on every page load), and a user counts as online for
     * PRESENCE_ONLINE_WITHIN seconds after that write. The second must stay
     * comfortably larger than the first or someone reading one long page would
     * flicker between Online and Offline.
     */
    public const PRESENCE_WRITE_EVERY = 60;

    public const PRESENCE_ONLINE_WITHIN = 300;

    /** Company prefix every login username starts with, e.g. "brite-juan". */
    public const USERNAME_PREFIX = 'brite';

    /** Lowercase letters, digits, and single - _ . separators between them. */
    public const USERNAME_PATTERN = '/^[a-z0-9]+(?:[-_.][a-z0-9]+)*$/';

    /**
     * Build the default username for a person: "brite-" + their first name,
     * lowercased and stripped to [a-z0-9]. A numeric suffix is added when the
     * name is already taken (brite-juan, brite-juan2, …).
     *
     * @param  list<string>  $reserve  Usernames to treat as taken in addition to the database.
     */
    public static function suggestUsername(string $firstName, array $reserve = []): string
    {
        $name = preg_replace('/[^a-z0-9]+/', '', strtolower(trim($firstName))) ?: 'user';
        $base = self::USERNAME_PREFIX.'-'.$name;

        $candidate = $base;
        for ($n = 2; in_array($candidate, $reserve, true) || static::where('username', $candidate)->exists(); $n++) {
            $candidate = $base.$n;
        }

        return $candidate;
    }

    /** Normalise user-typed usernames so "Brite-Juan " matches "brite-juan". */
    public static function normalizeUsername(?string $username): string
    {
        return strtolower(trim((string) $username));
    }

    /** True when the user made a request within the last PRESENCE_ONLINE_WITHIN seconds. */
    public function isOnline(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subSeconds(self::PRESENCE_ONLINE_WITHIN));
    }

    /**
     * Give the user an administrator-chosen (temporary) password. The account is
     * flagged so their next sign-in demands a password of their own choosing.
     */
    public function setTemporaryPassword(string $plain): void
    {
        $this->forceFill(['password' => $plain, 'must_change_password' => true])->save();
    }

    /** The user picked their own password; clear the temporary-password flag. */
    public function setOwnPassword(string $plain): void
    {
        $this->forceFill(['password' => $plain, 'must_change_password' => false])->save();
    }

    /** Blocked from signing in (kept on record, unlike a deleted account). */
    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    /** One word for the Status column: online · offline · disabled · deleted. */
    public function presenceStatus(): string
    {
        return match (true) {
            $this->trashed() => 'deleted',
            $this->isDisabled() => 'disabled',
            $this->isOnline() => 'online',
            default => 'offline',
        };
    }

    /** Record a request from this user, throttled to one write per PRESENCE_WRITE_EVERY. */
    public function touchLastSeen(): void
    {
        if ($this->last_seen_at === null || $this->last_seen_at->lte(now()->subSeconds(self::PRESENCE_WRITE_EVERY))) {
            $this->forceFill(['last_seen_at' => now()])->saveQuietly();
        }
    }

    public function scopeOnline(Builder $query): Builder
    {
        return $query->whereNull('disabled_at')
            ->where('last_seen_at', '>', now()->subSeconds(self::PRESENCE_ONLINE_WITHIN));
    }

    /**
     * Public URL of the user's uploaded profile photo, or null if none set.
     */
    public function getProfilePhotoUrlAttribute(): ?string
    {
        return $this->profile_photo_path
            ? Storage::disk('public')->url($this->profile_photo_path)
            : null;
    }

    /**
     * The employee (HR) profile linked to this login account.
     */
    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_login_at' => 'datetime',
            'disabled_at' => 'datetime',
            'must_change_password' => 'boolean',
            'password' => 'hashed',
        ];
    }
}
