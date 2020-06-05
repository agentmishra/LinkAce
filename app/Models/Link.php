<?php

namespace App\Models;

use App\Jobs\SaveLinkToWaybackmachine;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Venturecraft\Revisionable\RevisionableTrait;

/**
 * Class Link
 *
 * @package App\Models
 * @property int               $id
 * @property int               $user_id
 * @property string            $url
 * @property string            $title
 * @property string|null       $description
 * @property string|null       $icon
 * @property boolean           $is_private
 * @property int               $status
 * @property boolean           $check_disabled
 * @property Carbon|null       $created_at
 * @property Carbon|null       $updated_at
 * @property string|null       $deleted_at
 * @property Collection|Tag[]  $lists
 * @property Collection|Note[] $notes
 * @property Collection|Tag[]  $tags
 * @property User              $user
 * @method static Builder|Link byUser($user_id)
 */
class Link extends Model
{
    use SoftDeletes;
    use RevisionableTrait;

    public $table = 'links';

    public $fillable = [
        'user_id',
        'url',
        'title',
        'description',
        'icon',
        'is_private',
        'status',
        'check_disabled',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'is_private' => 'boolean',
        'status' => 'integer',
        'check_disabled' => 'boolean',
    ];

    public const STATUS_OK = 1;
    public const STATUS_MOVED = 2;
    public const STATUS_BROKEN = 3;

    public const DISPLAY_CARDS = 1;
    public const DISPLAY_LIST_SIMPLE = 2;
    public const DISPLAY_LIST_DETAILED = 0;

    // Revisions settings
    protected $revisionCleanup = true;
    protected $historyLimit = 50;

    public const REV_TAGS_NAME = 'revtags';
    public const REV_LISTS_NAME = 'revlists';


    /*
     | ========================================================================
     | SCOPES
     */

    /**
     * Scope for the user relation
     *
     * @param Builder $query
     * @param int     $user_id
     * @return mixed
     */
    public function scopeByUser($query, $user_id)
    {
        return $query->where('user_id', $user_id);
    }

    /**
     * Scope for the user relation
     *
     * @param Builder $query
     * @param bool    $is_private
     * @return mixed
     */
    public function scopePrivateOnly($query, bool $is_private)
    {
        return $query->where('is_private', $is_private);
    }

    /*
     | ========================================================================
     | RELATIONSHIPS
     */

    /**
     * @return BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsToMany
     */
    public function lists()
    {
        return $this->belongsToMany(LinkList::class, 'link_lists', 'link_id', 'list_id');
    }

    /**
     * @return BelongsToMany
     */
    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'link_tags', 'link_id', 'tag_id');
    }

    /**
     * @return HasMany
     */
    public function notes()
    {
        return $this->hasMany(Note::class, 'link_id');
    }

    /*
     | ========================================================================
     | METHODS
     */

    /**
     * Get the URL shortened to max 50 characters
     *
     * @return string
     */
    public function shortUrl()
    {
        return Str::limit(trim($this->url, '/'), 50);
    }

    /**
     * Get the title shortened to max 50 characters
     *
     * @param int $maxLength
     * @return string
     */
    public function shortTitle(int $maxLength = 50): string
    {
        return Str::limit($this->title, $maxLength);
    }

    /**
     * Get the domain of the URL
     *
     * @return string
     */
    public function domainOfURL()
    {
        $urlDetails = parse_url($this->url);
        return $urlDetails['host'] ?? $this->shortUrl(20);
    }

    /**
     * @return null|string
     */
    public function tagsForInput()
    {
        $tags = $this->tags;

        if ($tags->isEmpty()) {
            return null;
        }

        return $tags->implode('name', ',');
    }

    /**
     * @return null|string
     */
    public function listsForInput()
    {
        $lists = $this->lists;

        if ($lists->isEmpty()) {
            return null;
        }

        return $lists->implode('name', ',');
    }

    /**
     * @param string|null $additional_classes
     * @return string
     */
    public function getIcon(?string $additional_classes = null): string
    {
        if ($this->icon === null) {
            return '';
        }

        $icon = $this->icon;
        $title = null;

        // Override the icon by status if applicable
        if ($this->status === 2) {
            $icon = 'fa fa-external-link-alt text-warning';
            $title = trans('link.status.2');
        }

        if ($this->status === 3) {
            $icon = 'fa fa-unlink text-danger';
            $title = trans('link.status.3');
        }

        // Build the correct attributes
        $classes = 'fa-fw ' . $icon . ($additional_classes ? ' ' . $additional_classes : '');
        $title = $title ? ' title="' . $title . '"' : '';

        return '<i class="' . $classes . '" ' . $title . '></i>';
    }

    /**
     * Output a relative time inside a span with real time information
     *
     * @return string
     */
    public function addedAt()
    {
        $output = '<time-ago class="cursor-help"';
        $output .= ' datetime="' . $this->created_at->toIso8601String() . '"';
        $output .= ' title="' . formatDateTime($this->created_at) . '">';
        $output .= formatDateTime($this->created_at, true);
        $output .= '</time-ago>';

        return $output;
    }

    /**
     * @param string|int $linkId
     * @param string     $newUrl
     * @return bool
     */
    public static function urlHasChanged($linkId, string $newUrl): bool
    {
        $oldUrl = self::find($linkId)->url ?? null;
        return $oldUrl !== $newUrl;
    }

    /*
     * Dispatch the SaveLinkToWaybackmachine job, if Internet Archive backups
     * are enabled.
     * If the link is private, private Internet Archive backups must be enabled
     * too.
     */
    public function initiateInternetArchiveBackup(): void
    {
        if (usersettings('archive_backups_enabled') === '0') {
            return;
        }

        if ($this->is_private && usersettings('archive_private_backups_enabled') === '0') {
            return;
        }

        SaveLinkToWaybackmachine::dispatch($this);
    }

    /**
     * Create a base uri of the link url, consisting of a possible auth, the
     * hostname, a port if present, and the path. The scheme, fragments and
     * query parameters are dumped, as well as trailing slashes.
     * Then return all links that match this URI.
     *
     * If the host is not present, the URL might be broken, so do not search
     * for any duplicates.
     *
     * @return Collection
     */
    public function searchDuplicateUrls(): Collection
    {
        $parsed = parse_url($this->url);

        if (!isset($parsed['host'])) {
            return new Collection();
        }

        $auth = $parsed['user'] ?? '';
        $auth .= isset($parsed['pass']) ? ':' . $parsed['pass'] : '';

        $uri = $auth ? $auth . '@' : '';
        $uri .= $parsed['host'] ?? '';
        $uri .= isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $uri .= $parsed['path'] ?? '';

        return self::where('url', 'like', '%' . trim($uri, '/') . '%')->get();
    }
}
