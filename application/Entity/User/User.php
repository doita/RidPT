<?php
/**
 * Created by PhpStorm.
 * User: Rhilip
 * Date: 1/22/2020
 * Time: 2020
 */

declare(strict_types=1);

namespace App\Entity\User;

use App\Enums\User\Role;
use App\Enums\User\Status;
use App\Exceptions\NotExistException;
use Rid\Utils\Traits\ClassValueCache;

class User
{
    use ClassValueCache;

    //-- Start User Base Info --//
    protected int $id = 0;
    protected ?string $username;
    protected ?string $email;
    protected string $status = Status::DISABLED;
    protected int $class = Role::ANONYMOUS;
    protected string $passkey;

    protected int $uploadpos;
    protected int $downloadpos;

    protected int $uploaded = 0;
    protected int $downloaded = 0;
    protected int $seedtime = 0;
    protected int $leechtime = 0;

    protected ?string $avatar = null;

    protected string $bonus_seeding = '0';
    protected string $bonus_other = '0';

    protected int $invites;
    protected int $temp_invites;
    protected string $lang;

    //-- Start User Extended Info --//
    protected bool $extended_info_hit = false;
    protected ?string $create_at;
    protected ?string $last_login_at;
    protected ?string $last_access_at;
    protected ?string $last_upload_at;
    protected ?string $last_download_at;
    protected ?string $last_connect_at;
    protected ?string $register_ip;
    protected ?string $last_login_ip;
    protected ?string $last_access_ip;
    protected ?string $last_tracker_ip;

    //-- Start User Extra Info --//
    protected ?int $true_uploaded = 0;
    protected ?int $true_downloaded = 0;

    protected string $cache_key_extended;
    protected string $cache_key_extra;

    protected function getCacheNameSpace(): string
    {
        return $this->cache_key_extra;
    }

    public function __construct($id = 0)
    {
        $this->id = $id;
        $this->cache_key_extended = 'user:' . $id . ':extended_content';
        $this->cache_key_extra = 'user:' . $id . ':extra_content';

        $self = container()->get('dbal')->prepare('SELECT id, username, email, status, class, passkey, uploadpos, downloadpos, uploaded, downloaded, seedtime, leechtime, avatar, bonus_seeding, bonus_other, lang, invites, temp_invites FROM `users` WHERE id = :id LIMIT 1;')->bindParams([
            'id' => $id
        ])->fetchOne();

        if (false === $self) {
            throw new NotExistException('User not exist');  // It means this user id is invalid
        }
        $this->importAttributes($self);
    }

    // FIXME
    protected function importAttributes($config)
    {
        foreach ($config as $name => $value) {
            $this->$name = $value;
        }
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getClass(): int
    {
        return $this->class;
    }

    public function getPasskey(): string
    {
        return $this->passkey;
    }

    public function getUploadpos(): bool
    {
        return (bool)$this->uploadpos;
    }

    public function getDownloadpos(): bool
    {
        return (bool)$this->downloadpos;
    }

    public function getUploaded(): int
    {
        return $this->uploaded;
    }

    public function getDownloaded(): int
    {
        return $this->downloaded;
    }

    public function getSeedtime(): int
    {
        return $this->seedtime;
    }

    public function getLeechtime(): int
    {
        return $this->leechtime;
    }

    public function getAvatar(array $opts = []): string
    {
        if (config('user.avatar_provider') === 'gravatar') {
            /** Get a Gravatar URL for a specified email address.
             *
             * @param int|string $s Size in pixels, defaults to 80px [ 1 - 2048 ]
             * @param string $d Default imageset to use [ 404 | mp | identicon | monsterid | wavatar ]
             * @param string $r Maximum rating (inclusive) [ g | pg | r | x ]
             */
            $url = config('gravatar.base_url') . md5(strtolower(trim($this->email)));
            $url .= '?' . http_build_query([
                    's' => $opts['s'] ?? 80,
                    'd' => $opts['d'] ?? config('gravatar.default_fallback') ?? 'identicon',
                    'r' => $opts['r'] ?? config('gravatar.maximum_rating') ?? 'g'
                ], '', '&', PHP_QUERY_RFC3986);
            return $url;
        }/* elseif (config('user.avatar_provider') === 'remote') {
            // For example : another Image Hosting
        }*/ else {  // config('user.avatar_provider') === 'local'
            if ($this->avatar == '') {
                $this->avatar = '/static/avatar/default_avatar.jpg';
            }
        }

        return $this->avatar;
    }

    public function getInvites(): int
    {
        return $this->invites ?? 0;
    }

    public function getTempInvites(): int
    {
        return $this->temp_invites ?? 0;
    }

    public function getTotalInvites(): int
    {
        return $this->getInvites() + $this->getTempInvites();
    }

    public function getLang(): string
    {
        return $this->lang;
    }

    private function ratioHelper($a, $b)
    {
        if ($a == 0 && $b == 0) {
            return '---';
        } elseif ($b == 0) {
            return 'Inf.';
        } else {
            return $a / $b;
        }
    }

    public function getRatio()
    {
        return $this->ratioHelper($this->uploaded, $this->downloaded);
    }

    public function getTimeRatio()
    {
        return $this->ratioHelper($this->seedtime, $this->leechtime);
    }

    private function loadExtendProp()
    {
        if (false === $this->extended_info_hit) {
            if (false === $self = container()->get('redis')->get($this->cache_key_extended)) {
                $self = container()->get('dbal')->prepare('SELECT `create_at`, `register_ip`, `last_login_at`, `last_access_at`, `last_upload_at`, `last_download_at`, `last_connect_at`, `last_login_ip`, `last_access_ip`, `last_tracker_ip` FROM `users` WHERE id = :uid')->bindParams([
                    'uid' => $this->id
                ])->fetchOne() ?: [];
                container()->get('redis')->set($this->cache_key_extended, $self, 15 * 60);  // Cache This User Extend Detail for 15 minutes
            }

            $this->importAttributes($self);
            $this->extended_info_hit = true;
        }
    }

    /**
     * @return string|null
     */
    public function getCreateAt(): ?string
    {
        $this->loadExtendProp();
        return $this->create_at;
    }

    /**
     * @return string|null
     */
    public function getLastLoginAt(): ?string
    {
        $this->loadExtendProp();
        return $this->last_login_at;
    }

    /**
     * @return string|null
     */
    public function getLastAccessAt(): ?string
    {
        $this->loadExtendProp();
        return $this->last_access_at;
    }

    /**
     * @return string|null
     */
    public function getLastUploadAt(): ?string
    {
        $this->loadExtendProp();
        return $this->last_upload_at;
    }

    /**
     * @return string|null
     */
    public function getLastDownloadAt(): ?string
    {
        $this->loadExtendProp();
        return $this->last_download_at;
    }

    /**
     * @return string|null
     */
    public function getLastConnectAt(): ?string
    {
        $this->loadExtendProp();
        return $this->last_connect_at;
    }

    /**
     * @return string|null
     */
    public function getRegisterIp(): ?string
    {
        $this->loadExtendProp();
        return is_null($this->register_ip) ? '' : inet_ntop($this->register_ip);
    }

    /**
     * @return string|null
     */
    public function getLastLoginIp(): ?string
    {
        $this->loadExtendProp();
        return is_null($this->last_login_ip) ? '' : inet_ntop($this->last_login_ip);
    }

    /**
     * @return string|null
     */
    public function getLastAccessIp(): ?string
    {
        $this->loadExtendProp();
        return is_null($this->last_access_ip) ? '' : inet_ntop($this->last_access_ip);
    }

    /**
     * @return string|null
     */
    public function getLastTrackerIp(): ?string
    {
        $this->loadExtendProp();
        return is_null($this->last_tracker_ip) ? '' : inet_ntop($this->last_tracker_ip);
    }


    private function getRealTransfer(): array
    {
        return $this->getCacheValue('true_transfer', function () {
            return container()->get('dbal')->prepare('SELECT SUM(`true_uploaded`) as `uploaded`, SUM(`true_downloaded`) as `download` FROM `snatched` WHERE `user_id` = :uid')->bindParams([
                    "uid" => $this->id
                ])->fetchOne() ?? ['uploaded' => 0, 'download' => 0];
        });
    }

    public function getRealUploaded(): int
    {
        return (int)$this->getRealTransfer()['uploaded'];
    }

    public function getRealDownloaded(): int
    {
        return (int)$this->getRealTransfer()['download'];
    }

    public function getRealRatio()
    {
        return $this->ratioHelper($this->getRealUploaded(), $this->getRealDownloaded());
    }

    private function getPeerStatus($seeder = null)
    {
        $peer_status = $this->getCacheValue('peer_count', function () {
            $peer_count = container()->get('dbal')->prepare("SELECT `seeder`, COUNT(id) FROM `peers` WHERE `user_id` = :uid GROUP BY seeder")->bindParams([
                'uid' => $this->id
            ])->fetchAll() ?: [];
            return array_merge(['yes' => 0, 'no' => 0, 'partial' => 0], $peer_count);
        });

        return $seeder ? (int)$peer_status[$seeder] : $peer_status;
    }

    public function getActiveSeed()
    {
        return $this->getPeerStatus('yes');
    }

    public function getActiveLeech()
    {
        return $this->getPeerStatus('no');
    }

    public function getActivePartial()
    {
        return $this->getPeerStatus('partial');
    }

    public function getBonus(): float
    {
        return (float)$this->bonus_seeding + (float)$this->bonus_other;
    }

    public function updateBookmarkList()
    {
        $this->removeCacheValue('bookmark_list');
        $this->getBookmarkList();
    }

    public function getBookmarkList()
    {
        return $this->getCacheValue('bookmark_list', function () {
            return container()->get('dbal')->prepare('SELECT `tid` FROM `bookmarks` WHERE `uid` = :uid')->bindParams([
                'uid' => $this->id
            ])->fetchColumn() ?: [];
        });
    }

    public function getUnreadMessageCount()
    {
        return $this->getCacheValue('unread_message_count', function () {
            return container()->get('dbal')->prepare("SELECT COUNT(`id`) FROM `messages` WHERE receiver = :uid AND unread = 'no'")->bindParams([
                'uid' => $this->id
            ])->fetchScalar();
        });
    }

    public function getInboxMessageCount()
    {
        return $this->getCacheValue('inbox_count', function () {
            return container()->get('dbal')->prepare('SELECT COUNT(`id`) FROM `messages` WHERE `receiver` = :uid')->bindParams([
                'uid' => $this->id
            ])->fetchScalar();
        });
    }

    public function getOutboxMessageCount()
    {
        return $this->getCacheValue('outbox_count', function () {
            return container()->get('dbal')->prepare('SELECT COUNT(`id`) FROM `messages` WHERE `sender` = :uid')->bindParams([
                'uid' => $this->id
            ])->fetchScalar();
        });
    }

    public function inBookmarkList($tid = null): bool
    {
        return in_array($tid, $this->getBookmarkList());
    }

    public function isPrivilege($require_class): bool
    {
        if (is_string($require_class)) {
            $require_class = config('authority.' . $require_class) ?: 1;
        }

        return $this->class >= $require_class;
    }
}
