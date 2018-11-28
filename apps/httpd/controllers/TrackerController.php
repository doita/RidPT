<?php
/**
 * Created by PhpStorm.
 * User: Rhili
 * Date: 2018/11/22
 * Time: 15:01
 */

namespace apps\httpd\controllers;

use SandFoxMe\Bencode\Bencode;

use apps\common\controller\BaseController;
use apps\httpd\expections\TrackerException;

class TrackerController extends BaseController
{

    /** The Black List of Announce Port
     * See more : https://www.speedguide.net/port.php or other website
     *
     * @var array
     */
    protected $portBlacklist = [
        22,  // SSH Port
        53,  // DNS queries
        80, 81, 8080, 8081,  // Hyper Text Transfer Protocol (HTTP) - port used for web traffic
        411, 412, 413,  // 	Direct Connect Hub (unofficial)
        443,  // HTTPS / SSL - encrypted web traffic, also used for VPN tunnels over HTTPS.
        1214,  // Kazaa - peer-to-peer file sharing, some known vulnerabilities, and at least one worm (Benjamin) targeting it.
        3389,  // IANA registered for Microsoft WBT Server, used for Windows Remote Desktop and Remote Assistance connections
        4662,  // eDonkey 2000 P2P file sharing service. http://www.edonkey2000.com/
        6346, 6347,  // Gnutella (FrostWire, Limewire, Shareaza, etc.), BearShare file sharing app
        6699,  // Port used by p2p software, such as WinMX, Napster.
        6881, 6882, 6883, 6884, 6885, 6886, 6887, // BitTorrent part of full range of ports used most often (unofficial)
        //65000, 65001, 65002, 65003, 65004, 65005, 65006, 65007, 65008, 65009, 65010   // For unknown Reason 2333~
    ];

    /**
     * @return string
     */
    public function actionIndex()
    {
        // Set Response Header ( Format, HTTP Cache )
        $this->Response->setHeader("Content-Type", "text/plain; charset=utf-8");
        $this->Response->setHeader("Pragma", "no-cache");

        $userInfo = null;
        $torrentInfo = null;

        try {
            // Block NON-GET requests (Though non-GET request will not match this Route )
            if (!$this->Request->isGet())
                throw new TrackerException(110, [":method" => $this->Request->method()]);

            if (!$this->Config->get("base.enable_tracker_system"))
                throw new TrackerException(100);

            $this->blockClient();

            $action = $this->Request->route("{tracker_action}");
            $this->checkUserAgent($action == 'scrape');

            $this->checkPasskey($userInfo);

            switch ($action) {
                // Tracker Protocol Extension: Scrape - http://www.bittorrent.org/beps/bep_0048.html
                case 'scrape':
                    {
                        if (!$this->Config->get("tracker.enable_scrape")) throw new TrackerException(101);

                        $this->checkScrapeFields($info_hash_array);
                        $this->generateScrapeResponse($info_hash_array, $rep_dict);

                        return Bencode::encode($rep_dict);
                    }

                case 'announce':
                    {
                        if (!$this->Config->get("tracker.enable_announce")) throw new TrackerException(102);

                        $this->checkAnnounceFields($queries);
                        $this->lockAnnounceDuration($queries);  // Lock min announce before User Data update to avoid flood

                        /**
                         * If nothing error we start to get and cache the the torrent_info from database,
                         * and check peer's privilege
                         */
                        $this->getTorrentInfo($queries, $userInfo, $torrentInfo);

                        /** Get peer's Role
                         *
                         * In a P2P network , a peer's role can be describe as `seeder` or `leecher`,
                         * Which We can judge from the `$left=` params.
                         *
                         * However BEP 0021 `Extension for partial seeds` add a new role `partial seed`.
                         * A partial seed is a peer that is incomplete without downloading anything more.
                         * This happens for multi file torrents where users only download some of the files.
                         *
                         * So another `&event=paused` is need to judge if peer is `paused` or `partial seed`.
                         * However we still calculate it's duration as leech time, but only seed leecher info to him
                         *
                         * See more: http://www.bittorrent.org/beps/bep_0021.html
                         *
                         */
                        $role = ($queries['left'] == 0) ? 'yes' : 'no';
                        if ($queries['event'] == 'paused')
                            $role = 'partial';

                        // Start Database Transaction for below CRUD Action
                        $this->Database->beginTransaction();
                        try {
                            $this->processAnnounceRequest($queries, $role, $userInfo, $torrentInfo);
                            $this->Database->commit();
                        } catch (\Exception $e) {
                            $this->Database->rollback();
                            throw new TrackerException(999, [":test" => $e->getMessage()]);
                        }

                        $this->generateAnnounceResponse($queries, $role, $torrentInfo, $rep_dict);
                        return Bencode::encode($rep_dict);
                    }

                default:
                    throw new TrackerException(111, [":action" => $action]);
            }
        } catch (TrackerException $e) {
            // Record agent deny log in Table `agent_deny_log` when the user and torrent info get success
            if (!is_null($userInfo) && !is_null($torrentInfo)) {
                $raw_header = "";
                foreach ($this->Request->header() as $key => $value) {
                    $raw_header .= "$key : $value \n";
                }
                $req_info = $this->Request->server('query_string') . "\n\n" . $raw_header;

                $this->Database->createCommand("INSERT INTO `agent_deny_log`(`tid`, `uid`, `user_agent`, `peer_id`, `req_info`, `msg`) 
                VALUES (:tid,:uid,:ua,:peer_id,:req_info,:msg) 
                ON DUPLICATE KEY UPDATE `user_agent` = VALUES(`user_agent`),`peer_id` = VALUES(`peer_id`),
                                        `req_info` = VALUES(`req_info`),`msg` = VALUES(`msg`), 
                                        `last_action_at` = NOW();")->bindParams([
                    "tid" => $torrentInfo["id"],
                    'uid' => $userInfo["id"],
                    'ua' => $this->Request->header("user-agent"),
                    'peer_id' => $this->Request->get("peer_id"),
                    'req_info' => $req_info,
                    'msg' => $e->getMessage()
                ])->execute();
            }

            return Bencode::encode([
                "failure reason" => $e->getMessage(),
            ]);
        }


    }

    /** Check Client's User-Agent, (If not pass this Check , A TrackerException will throw)
     * @throws TrackerException
     */
    private function blockClient()
    {
        // Block Browser by check it's User-Agent
        if (preg_match('/(^Mozilla|Browser|AppleWebKit|^Opera|^Links|^Lynx|[Bb]ot)/', $this->Request->header("user-agent"))) {
            throw new TrackerException(120);
        }

        // Block Other Browser, Crawler (, May Cheater or Faker Client) by check Requests headers
        if ($this->Request->header("accept-language") || $this->Request->header('referer')
            || $this->Request->header("accept-charset")

            /**
             * This header check may block Non-bittorrent client `Aria2` to access tracker,
             * Because they always add this header which other clients don't have.
             */
            || $this->Request->header("want-digest")

            /**
             * If your tracker is behind the Cloudflare or other CDN (proxy) Server,
             * Comment this line to avoid unexpected Block ,
             * Because They may add the Cookie header ,
             * Otherwise you should enabled this header check
             *
             * For example :
             *
             * The Cloudflare will add `__cfduid` Cookies to identify individual clients behind a shared IP address
             * and apply security settings on a per-client basis.
             *
             * See more on : https://support.cloudflare.com/hc/en-us/articles/200170156
             *
             */
            //|| $this->Request->header("cookie")
        )
            throw new TrackerException(121);

        // Should also Block those too long User-Agent. ( For Database reason
        if (strlen($this->Request->header("user-agent")) > 64)
            throw new TrackerException(122);
    }

    /** Check Passkey Exist and Valid First, And We Get This Account Info
     * @param $userInfo
     * @throws TrackerException
     */
    private function checkPasskey(&$userInfo)
    {
        $passkey = $this->Request->get("passkey");

        // First Check The param `passkey` is exist and valid
        if (is_null($passkey))
            throw new TrackerException(130, [":attribute" => "passkey"]);
        if (strlen($passkey) != 32)
            throw new TrackerException(132, [":attribute" => "passkey", ":rule" => "32"]);
        if (strspn(strtolower($passkey), 'abcdef0123456789') != 32)
            throw new TrackerException(131, [":attribute" => "passkey", ":reason" => "The format of passkey isn't correct"]);

        // Get userInfo from Redis Cache and then Database
        $userInfo = $this->Redis->get("user_passkey_" . $passkey . "_content");
        if ($userInfo === false) {
            // If Cache breakdown , We will get User info from Database and then cache it
            // Notice: if this passkey is not find in Database , a null will be cached.
            $userInfo = $this->Database
                ->createCommand("SELECT `id`,`status`,`passkey`,`downloadpos`,`class` FROM `users` WHERE `passkey` = :passkey LIMIT 1")
                ->bindParams(["passkey" => $passkey])->queryOne() ?: null;
            $this->Redis->setex("user_passkey_" . $passkey . "_content", 3600, $userInfo);
        }

        /**
         * Throw Exception If user can't Download From our sites
         * The following situation:
         *  - The user don't register in our site or they may use the fake or old passkey which is not exist.
         *  - The user's status is not `confirmed`
         *  - The user's download Permission is disabled.
         */
        if (is_null($userInfo)) throw new TrackerException(140);
        if ($userInfo["status"] != "confirmed") throw new TrackerException(141, [":status" => $userInfo["status"]]);
        if ($userInfo["downloadpos"] == "no") throw new TrackerException(142);
    }

    /**
     * @param $info_hash_array
     * @throws TrackerException
     */
    private function checkScrapeFields(&$info_hash_array)
    {
        preg_match_all('/info_hash=([^&]*)/i', urldecode($this->Request->server('query_string')), $info_hash_match);

        $info_hash_array = $info_hash_match[1];
        if (count($info_hash_array) < 1) {
            throw new TrackerException(130, [":attribute" => 'info_hash']);
        } else {
            foreach ($info_hash_array as $item) {
                if (strlen($item) != 20)
                    throw new TrackerException(133, [":attribute" => 'info_hash', ":rule" => strlen($item)]);
            }
        }
    }

    private function generateScrapeResponse($info_hash_array, &$rep_dict)
    {
        $torrent_details = [];
        foreach ($info_hash_array as $item) {
            $metadata = $this->Redis->get("torrent_hash_" . $item . "_scrape_content");
            if ($metadata === false) {
                $metadata = $this->Database
                    ->createCommand("SELECT incomplete, complete , downloaded FROM torrents WHERE info_hash = :info LIMIT 1")
                    ->bindParams(["info" => $item])->queryOne() ?: null;
                $this->Redis->setex("torrent_hash_" . $item . "_scrape_content", 350, $metadata);
            }
            if (!is_null($metadata)) $torrent_details[$item] = $metadata;  // Append it to tmp array only it exist.
        }

        $rep_dict = ["files" => $torrent_details];
    }

    /**
     * @param bool $onlyCheckUA
     * @throws TrackerException
     */
    private function checkUserAgent(bool $onlyCheckUA = false)
    {
        // Get Client White-And-Exception List From Database and storage it in Redis Cache
        $allowedFamily = $this->Redis->get("allowed_client_list");
        if ($allowedFamily === false) {
            $allowedFamily = $this->Database->createCommand("SELECT * FROM `agent_allowed_family` WHERE `enabled` = 'yes' ORDER BY `hits` DESC")->queryAll();
            $this->Redis->setex("allowed_client_list", 86400, $allowedFamily);
        }

        $allowedFamilyException = $this->Redis->get("allowed_client_exception_list");
        if ($allowedFamilyException === false) {
            $allowedFamilyException = $this->Database->createCommand("SELECT * FROM `agent_allowed_exception`")->queryAll();
            $this->Redis->setex("allowed_client_exception_list", 86400, $allowedFamilyException);
        }

        // Start Check Client by `User-Agent` and `peer_id`
        $userAgent = $this->Request->header("user-agent");
        $peer_id = $this->Request->get("peer_id") ?: "";

        $agentAccepted = null;
        $peerIdAccepted = null;
        $acceptedAgentFamilyId = null;
        $acceptedAgentFamilyException = null;

        foreach ($allowedFamily as $allowedItem) {
            // Initialize FLAG before each loop
            $agentAccepted = false;
            $peerIdAccepted = false;
            $acceptedAgentFamilyId = 0;
            $acceptedAgentFamilyException = false;

            // Check User-Agent
            if ($allowedItem['agent_pattern'] != '') {
                if (!preg_match($allowedItem['agent_pattern'], $allowedItem['agent_start'], $agentShould))
                    throw new TrackerException(123, [":pattern" => "User-Agent", ":start" => $allowedItem['start_name']]);

                if (preg_match($allowedItem['agent_pattern'], $userAgent, $agentMatched)) {
                    if ($allowedItem['agent_match_num'] > 0) {
                        for ($i = 0; $i < $allowedItem['agent_match_num']; $i++) {
                            if ($allowedItem['agent_matchtype'] == 'hex') {
                                $agentMatched[$i + 1] = hexdec($agentMatched[$i + 1]);
                                $agentShould[$i + 1] = hexdec($agentShould[$i + 1]);
                            } else {
                                $agentMatched[$i + 1] = intval($agentMatched[$i + 1]);
                                $agentShould[$i + 1] = intval($agentShould[$i + 1]);
                            }

                            // Compare agent version number from high to low
                            // The high version number is already greater than the requirement, Break,
                            if ($agentMatched[$i + 1] > $agentShould[$i + 1]) {
                                $agentAccepted = true;
                                break;
                            }
                            // Below requirement
                            if ($agentMatched[$i + 1] < $agentShould[$i + 1])
                                throw new TrackerException(124, [":start" => $allowedItem['start_name']]);
                            // Continue to loop. Unless the last bit is equal.
                            if ($agentMatched[$i + 1] == $agentShould[$i + 1] && $i + 1 == $allowedItem['agent_match_num']) {
                                $agentAccepted = true;
                            }
                        }
                    } else {
                        $agentAccepted = true;  // No need to compare `version number`
                    }
                }
            } else {
                $agentAccepted = true;  // No need to compare `agent pattern`
            }

            if ($onlyCheckUA) {
                if ($agentAccepted) break; else continue;
            }

            // Check Peer_id
            if ($allowedItem['peer_id_pattern'] != '') {
                if (!preg_match($allowedItem['peer_id_pattern'], $allowedItem['peer_id_start'], $peerIdShould))
                    throw new TrackerException(123, [":pattern" => "peer_id", ":start" => $allowedItem['start_name']]);

                if (preg_match($allowedItem['peer_id_pattern'], $peer_id, $peerIdMatched)) {
                    if ($allowedItem['peer_id_match_num'] > 0) {
                        for ($i = 0; $i < $allowedItem['peer_id_match_num']; $i++) {
                            if ($allowedItem['peer_id_matchtype'] == 'hex') {
                                $peerIdMatched[$i + 1] = hexdec($peerIdMatched[$i + 1]);
                                $peerIdShould[$i + 1] = hexdec($peerIdShould[$i + 1]);
                            } else {
                                $peerIdMatched[$i + 1] = intval($peerIdMatched[$i + 1]);
                                $peerIdShould[$i + 1] = intval($peerIdShould[$i + 1]);
                            }
                            // Compare agent version number from high to low
                            // The high version number is already greater than the requirement, Break,
                            if ($peerIdMatched[$i + 1] > $peerIdShould[$i + 1]) {
                                $peerIdAccepted = true;
                                break;
                            }
                            // Below requirement
                            if ($peerIdMatched[$i + 1] < $peerIdShould[$i + 1])
                                throw new TrackerException(114, [":start" => $allowedItem['start_name']]);
                            // Continue to loop. Unless the last bit is equal.
                            if ($peerIdMatched[$i + 1] == $peerIdShould[$i + 1] && $i + 1 == $allowedItem['agent_match_num']) {
                                $peerIdAccepted = true;
                            }
                        }
                    } else {
                        $peerIdAccepted = true;  // No need to compare `peer_id`
                    }
                }
            } else {
                $peerIdAccepted = true;  // No need to compare `Peer id pattern`
            }

            // Stop check Loop if matched once
            if ($agentAccepted && $peerIdAccepted) {
                $acceptedAgentFamilyId = $allowedItem['id'];
                $acceptedAgentFamilyException = $allowedItem['exception'] == 'yes' ? true : false;
                break;
            }
        }

        if ($onlyCheckUA) {
            if (!$agentAccepted) throw new TrackerException(125, [":ua" => $userAgent]);
            return;
        }

        if ($agentAccepted && $peerIdAccepted) {
            if ($acceptedAgentFamilyException) {
                foreach ($allowedFamilyException as $exceptionItem) {
                    // Throw TrackerException
                    if ($exceptionItem['family_id'] == $acceptedAgentFamilyId
                        && preg_match($exceptionItem['peer_id'], $peer_id)
                        && ($userAgent == $exceptionItem['agent'] || !$exceptionItem['agent'])
                    )
                        throw new TrackerException(126, [":ua" => $userAgent, ":comment" => $exceptionItem['comment']]);
                }
            }
        } else {
            throw new TrackerException(125, [":ua" => $userAgent]);
        }
    }

    /** See more: http://www.bittorrent.org/beps/bep_0003.html#trackers
     * @param array $queries
     * @throws TrackerException
     */
    private function checkAnnounceFields(&$queries = [])
    {
        // Part.1 check Announce **Need** Fields
        // Notice: param `passkey` is not require in BEP , but is required in our private torrent tracker system
        foreach (['info_hash', 'peer_id', 'port', 'uploaded', 'downloaded', 'left', "passkey"] as $item) {
            $item_data = $this->Request->get($item);
            if (!is_null($item_data)) {
                $queries[$item] = $item_data;
            } else {
                throw new TrackerException(130, [":attribute" => $item]);
            }
        }

        foreach (['info_hash', 'peer_id'] as $item) {
            if (strlen($queries[$item]) != 20)
                throw new TrackerException(133, [":attribute" => $item, ":rule" => 20]);
        }

        foreach (['uploaded', 'downloaded', 'left'] as $item) {
            $item_data = $queries[$item];
            if (!is_numeric($item_data) || $item_data < 0)
                throw new TrackerException(134, [":attribute" => $item]);
        }

        $this->checkPortFields($queries["port"]);

        // Part.2 check Announce **Option** Fields
        foreach ([
                     'event' => '', 'no_peer_id' => 1, 'compact' => 0,
                     'numwant' => 50, 'corrupt' => 0, 'key' => '',
                     // 'ip' => '', 'ipv4' => '', 'ipv6' => ''
                 ] as $item => $value) {
            $queries[$item] = $this->Request->get($item) ?: $value;
        }

        foreach (['numwant', 'corrupt', 'no_peer_id', 'compact'] as $item) {
            if (!is_numeric($queries[$item]) || $queries[$item] < 0)
                throw new TrackerException(134, [":attribute" => $item]);
        }

        if (!in_array(strtolower($queries['event']), ['started', 'completed', 'stopped', 'paused', '']))
            throw new TrackerException(136, [":event" => strtolower($queries['event'])]);

        if ($queries['port'] == 0 && strtolower($queries['event']) != 'stopped')
            throw new TrackerException(137, [":event" => strtolower($queries['event'])]);

        // TODO Part.3 check Announce *IP* Fields
        $queries["ip"] = filter_var($this->Request->header("x-forwarded-for"), FILTER_VALIDATE_IP) ?:
            filter_var($this->Request->header("client-ip"), FILTER_VALIDATE_IP) ?:
                $this->Request->server("remote_addr");

    }

    /** Check Port
     *
     * Normally , the port must in 1 - 65535 , that is ( $port > 0 && $port < 0xffff )
     * However, in some case , When `&event=stopped` the port may set to 0.
     * @param $port
     * @throws TrackerException
     */
    private function checkPortFields($port)
    {
        if (!is_numeric($port) || $port < 0 || $port > 0xffff || in_array($port, $this->portBlacklist))
            throw new TrackerException(135, [":port" => $port]);
    }

    /**
     * @param $queries
     * @param $userInfo
     * @param $torrentInfo
     * @throws TrackerException
     */
    private function getTorrentInfo($queries, $userInfo, &$torrentInfo)
    {
        $info_hash = $queries["info_hash"];

        $torrentInfo = $this->Redis->get('torrent_hash_' . $info_hash . '_content');
        if ($torrentInfo === false) {
            $torrentInfo = $this->Database
                ->createCommand("SELECT id , info_hash , owner_id , status , incomplete , complete FROM torrents WHERE info_hash = :info LIMIT 1")
                ->bindParams(["info" => $info_hash])->queryOne() ?: null;
            $this->Redis->setex('torrent_hash_' . $info_hash . '_content', 350, $torrentInfo);
        }
        if (is_null($torrentInfo)) throw new TrackerException(150);

        switch ($torrentInfo["status"]) {
            case 'confirmed' :
                break; // Do nothing , just break torrent status check when it is a confirmed torrent
            case 'pending' :
                {
                    // For Pending torrent , we just allow it's owner and other user who's class great than your config set to connect
                    if ($torrentInfo["owner_id"] != $userInfo["id"]
                        || $userInfo["class"] < $this->Config->get("authority.see_pending_torrent"))
                        throw new TrackerException(151, [":status" => $torrentInfo["status"]]);
                    break;
                }
            case 'banned' :
                {
                    // For Banned Torrent , we just allow the user who's class great than your config set to connect
                    if ($userInfo["class"] < $this->Config->get("authority.see_banned_torrent"))
                        throw new TrackerException(151, [":status" => $torrentInfo["status"]]);
                    break;
                }
            case 'deleted' :
            default:
                {
                    // For Deleted Torrent , no one can connect anymore..
                    throw new TrackerException(151, [":status" => $torrentInfo["status"]]);
                }
        }
    }

    /**
     * @param $queries
     * @param $seeder
     * @param $userInfo
     * @param $torrentInfo
     * @throws TrackerException
     */
    private function processAnnounceRequest($queries, $seeder, $userInfo, $torrentInfo)
    {
        $timeKey = ($seeder == 'yes') ? 'seed_time' : 'leech_time';

        // Try to fetch session from Table `peers`
        $self = $this->Database->createCommand("SELECT `uploaded`,`downloaded`,(NOW() - `last_action_at`) as `duration` 
        FROM `peers` WHERE `user_id`=:uid AND `torrent_id`=:tid AND `peer_id`=:pid LIMIT 1;")->bindParams([
            "uid" => $userInfo["id"], "tid" => $torrentInfo["id"], "pid" => $queries["peer_id"]
        ])->queryOne();

        if (!$self) unset($self);

        // If this session is not exist , We should check if this peer can open this NEW session then create it
        if (!isset($self)) {
            $selfCount = $this->Database->createCommand("SELECT COUNT(*) AS `count` FROM `peers` WHERE `user_id` = :uid AND `torrent_id` = :tid;")->bindParams([
                "uid" => $userInfo["id"],
                "tid" => $torrentInfo["id"]
            ])->queryScalar();

            // Ban one torrent seeding/leech at muti-location due to your site config
            if ($seeder == 'yes') { // if this peer's role is seeder
                if ($selfCount >= ($this->Config->get('tracker.user_max_seed')))
                    throw new TrackerException(160, [":count" => $this->Config->get('tracker.user_max_seed')]);
            } else {
                if ($selfCount >= ($this->Config->get('tracker.user_max_leech')))
                    throw new TrackerException(161, [":count" => $this->Config->get('tracker.user_max_leech')]);
            }

            // TODO Wait System
            // TODO Max SLots System
        }

        // So that , We can calculate Announce data on a exist session
        $trueUploaded = max(0, $queries['uploaded'] - (isset($self) ? $self['uploaded'] : 0));
        $trueDownloaded = max(0, $queries['downloaded'] - (isset($self) ? $self['downloaded'] : 0));
        $duration = max(0, (isset($self) ? $self['duration'] : 0));

        if ($this->Config->get("tracker.enable_upspeed_check")) {
            if ($userInfo["class"] < $this->Config->get("authority.pass_tracker_upspeed_check") && $duration > 0)
                $this->checkUpspeed($userInfo, $torrentInfo, $trueUploaded, $trueDownloaded, $duration);
        }

        $this->getTorrentBuff($userInfo['id'], $torrentInfo["id"], $trueUploaded, $trueDownloaded, $thisUploaded, $thisDownloaded);

        // Update Table `peers`, `snatched` by it's event tag
        // Notice : there MUST have history record in Table `snatched` if session is exist !!!!!!!!
        if (isset($self)) {
            if ($queries["event"] === "stopped") {
                // Peer stop seeding or leeching and should remove this peer from our peer list and should update his data.
                $this->Database->createCommand("DELETE FROM `peers` WHERE `user_id` = :uid AND `torrent_id` = :tid AND `peer_id` = :pid")->bindParams([
                    "uid" => $userInfo["id"],
                    "tid" => $torrentInfo["id"],
                    "pid" => $queries["peer_id"]
                ])->execute();
            } else {
                // if session is exist but event!=stopped , we should continue the old session
                $this->Database->createCommand("UPDATE `peers` SET `agent`=:agent,ip=INET6_ATON(:ip),`port`=:port,`seeder`=:seeder,
                   `uploaded`=`uploaded` + :uploaded,`downloaded`= `downloaded` + :download,`to_go` = :left,
                   `last_action_at`=NOW(),`corrupt`=:corrupt,`key`=:key 
                   WHERE `user_id` = :uid AND `torrent_id` = :tid AND `peer_id`=:pid")->bindParams([
                    "agent" => $this->Request->header("user-agent"),
                    "ip" => $queries["ip"], "port" => $queries["port"],
                    "seeder" => $seeder, "uploaded" => $trueUploaded, "download" => $trueDownloaded, "left" => $queries["left"],
                    "corrupt" => $queries["corrupt"], "key" => $queries["key"],
                    "uid" => $userInfo["id"], "tid" => $torrentInfo["id"], "pid" => $queries["peer_id"]
                ])->execute();
            }
            if ($this->Database->getRowCount() > 0) {   // It means that the delete or update query affected so we can safety update `snatched` table
                $this->Database->createCommand("UPDATE `snatched` SET `true_uploaded` = `true_uploaded` + :true_up,`true_downloaded` = `true_downloaded` + :true_dl,
                      `this_uploaded` = `this_uploaded` + :this_up, `this_download` = `this_download` + :this_dl, `to_go` = :left, `{$timeKey}`=`{$timeKey}` + :duration,
                      `agent` = :agent WHERE `torrent_id` = :tid AND `user_id` = :uid")->bindParams([
                    "true_up" => $trueUploaded, "true_dl" => $trueDownloaded, "this_up" => $thisUploaded, "this_dl" => $thisDownloaded,
                    "left" => $queries["left"], "duration" => $duration, "agent" => $this->Request->header("user-agent"),
                    "tid" => $torrentInfo["id"], "uid" => $userInfo["id"]
                ])->execute();
            }
        } elseif ($queries['event'] != 'stopped') {
            // if session is not exist ,and the session is not exist a new session should start

            // First we create this NEW session in database
            $this->Database->createCommand("INSERT INTO `peers`(`user_id`, `torrent_id`, `peer_id`, `agent`, ip, `port`, `seeder`, `uploaded`, `downloaded`, `to_go`, `finished`, `started_at`, `last_action_at`, `corrupt`, `key`)
            VALUES (:uid,:tid,:pid,:agent,INET6_ATON(:ip),:port,:seeder,:upload,:download,:to_go,0,NOW(),NOW(),:corrupt,:key)")->bindParams([
                "uid" => $userInfo["id"], "tid" => $torrentInfo["id"], "pid" => $queries["peer_id"],
                "agent" => $this->Request->header("user-agent"),
                "upload" => $trueUploaded, "download" => $trueDownloaded, "to_go" => $queries["left"],
                "ip" => $queries["ip"], "port" => $queries["port"],
                "seeder" => $seeder, "corrupt" => $queries["corrupt"], "key" => $queries["key"],
            ])->execute();

            // Search history record, and create new record if not exist.
            $selfRecordCount = $this->Database->createCommand("SELECT COUNT(`id`) FROM snatched WHERE user_id=:uid AND torrent_id = :tid")->bindParams([
                "uid" => $userInfo["id"],
                "tid" => $torrentInfo["id"]
            ])->queryScalar();

            if ($selfRecordCount == 0) {
                $this->Database->createCommand("INSERT INTO snatched (`user_id`,`torrent_id`,`agent`,`port`,`true_downloaded`,`true_uploaded`,`this_download`,`this_uploaded`,`to_go`,`$timeKey`,`create_at`,`last_action_at`) 
                VALUES (:uid,:tid,:agent,:port,:true_dl,:true_up,:this_dl,:this_up,:to_go,:time,NOW(),NOW())")->bindParams([
                    "uid" => $userInfo["id"], "tid" => $torrentInfo["id"],
                    "agent" => $this->Request->header("user-agent"), "port" => $queries["port"],
                    "true_up" => $trueUploaded, "true_dl" => $trueDownloaded,
                    "this_up" => $thisUploaded, "this_dl" => $thisDownloaded,
                    "to_go" => $queries["left"], "time" => $duration
                ])->execute();
            }
        }

        // TODO Update `torrents`

        // Deal with completed event
        if ($queries["event"] === "completed") {
            $this->Database->createCommand("UPDATE `snatched` SET `finished` = 'yes' , finish_ip = INET6_ATON(:ip) , finish_at = NOW() WHERE user_id = :uid AND torrent_id = :tid")->bindParams([
                "ip" => $queries["ip"],
                "uid" => $userInfo["id"], "tid" => $torrentInfo["id"],
            ]);
        }

        // Update Table `users` , record his upload and download data and connect time information
        $this->Database->createCommand("UPDATE `users` SET uploaded = uploaded + :upload, downloaded = downloaded + :download, "
            . ($trueUploaded > 0 ? "last_upload_at=NOW()," : "") . ($trueDownloaded > 0 ? "last_download_at=NOW()," : "") .
            "last_connect_at=NOW() WHERE id = :uid")->bindParams([
            "upload" => $thisUploaded,
            "download" => $thisDownloaded,
            "uid" => $userInfo["id"],
        ])->execute();

    }

    /** Cheater check function from NexusPHP based on user upload speed check
     *
     * See raw code from : https://github.com/ZJUT/NexusPHP/blob/master/include/functions_announce.php#L76
     *
     * @param $userInfo
     * @param $torrentInfo
     * @param $trueUploaded
     * @param $trueDownloaded
     * @param $duration
     * @throws TrackerException
     */
    private function checkUpspeed($userInfo, $torrentInfo, $trueUploaded, $trueDownloaded, $duration)
    {
        $upspeed = (($trueUploaded > 0 && $duration > 0) ? $trueUploaded / $duration : 0);

        $logCheater = function ($commit) use ($userInfo, $torrentInfo, $trueUploaded, $trueDownloaded, $duration) {
            $this->Database->createCommand("INSERT INTO `cheaters`(`userid`, `torrentid`, `uploaded`, `downloaded`, `anctime`, `seeders`, `leechers`, `hit`, `commit`, `reviewed`, `reviewed_by`) 
            VALUES (:uid, :tid, :uploaded, :downloaded, :anctime, :seeders, :leechers, :hit, :msg, :reviewed, :reviewed_by)  
            ON DUPLICATE KEY UPDATE `hit` = `hit` + 1, `reviewed` = 0,`reviewed_by` = '',`commit` = VALUES(`commit`)")->bindParams([
                "uid" => $userInfo["id"], "tid" => $torrentInfo["id"],
                "uploaded" => $trueUploaded, "downloaded" => $trueDownloaded, "anctime" => $duration,
                "seeders" => $torrentInfo["complete"], "leechers" => $torrentInfo["incomplete"],
                "hit" => 1, "msg" => $commit,
                "reviewed" => 0, "reviewed_by" => ""
            ])->execute();
        };

        // Uploaded more than 1 GB with uploading rate higher than 100 MByte/S (For Consertive level). This is no doubt cheating.
        if ($trueUploaded > 1 * (1024 ** 3) && $upspeed > 100 * (1024 ** 2)) {
            $logCheater("User account was automatically disabled by system");
            // Disable users and Delete user content in cache , so that user cannot get any data when next announce.
            $this->Database->createCommand("UPDATE users SET status = 'banned' WHERE id = :uid;")->bindParams([
                "uid" => $userInfo["id"],
            ])->execute();
            $this->Redis->del("user_passkey_" . $userInfo["passkey"] . "_content");
            throw new TrackerException();
        }

        // Uploaded more than 1 GB with uploading rate higher than 25 MByte/S (For Consertive level). This is likely cheating.
        if ($trueUploaded > 1 * (1024 ** 3) && $upspeed > 25 * (1024 ** 2))
            $logCheater("Abnormally high uploading rate");

        // Uploaded more than 1 GB with uploading rate higher than 1 MByte/S when there is less than 8 leechers (For Consertive level). This is likely cheating.
        if ($trueUploaded > 1 * (1024 ** 3) && $upspeed > 1 * (1024 ** 2))
            $logCheater("User is uploading fast when there is few leechers");

        //Uploaded more than 10 MB with uploading speed faster than 100 KByte/S when there is no leecher. This is likely cheating.
        if ($trueUploaded > 10 * (1024 ** 2) && $upspeed > 100 * 1024 && $torrentInfo["incomplete"] == 0)
            $logCheater("User is uploading when there is no leecher");
    }

    private function getTorrentBuff($userid, $torrentid, $trueUploaded, $trueDownloaded, &$thisUploaded, &$thisDownloaded)
    {
        $buff = $this->Redis->get("user_" . $userid . "_torrent_" . $torrentid . "_buff");
        if ($buff === false) {
            $buff = $this->Database->createCommand("SELECT COALESCE(MAX(`upload_ratio`),1) as `up_ratio`, COALESCE(MIN(`download_ratio`),1) as `dl_ratio` FROM `torrents_buff` 
            WHERE start_at < NOW() AND NOW() < expired_at AND (torrentid = :tid OR torrentid = 0) AND (beneficiary_id = :bid OR beneficiary_id = 0);")->bindParams([
                "tid" => $torrentid,
                "bid" => $userid
            ])->queryOne();
            $this->Redis->setex("user_" . $userid . "_torrent_" . $torrentid . "_buff", 350, $buff);
        }
        $thisUploaded = $trueUploaded * ($buff["up_ratio"] ?: 1);
        $thisDownloaded = $trueDownloaded * ($buff["dl_ratio"] ?: 1);
    }

    private function generateAnnounceResponse($queries, $role, $torrentInfo, &$rep_dict)
    {
        // TODO support `no_peer_id` and `compact` params
        $peerList = [];
        $rep_dict = [
            "interval" => $this->Config->get("tracker.interval") + rand(5, 20),   // random interval to avoid BOOM
            "min interval" => $this->Config->get("tracker.min_interval") + rand(1, 5),
            "complete" => 0, // FIXME get real announce data
            "incomplete" => 0,
            "peers" => &$peerList
        ];

        $limit = ($queries["numwant"] <= 50) ? $queries["numwant"] : 50;

        $peers = $this->Database->createCommand("SELECT INET6_NTOA(`ip`) as `ip`,`port`,`peer_id` from `peers` WHERE torrent_id = :tid " .
            "AND peer_id != :pid " .    // Don't select user himself
            ($role != "no" ? "AND `seeder`='no' " : " ") .  // Don't report seeds to other seeders
            "ORDER BY RAND() LIMIT $limit")->bindParams([
            "tid" => $torrentInfo["id"], "pid" => $queries["peer_id"]
        ])->queryAll();

        foreach ($peers as $peer) {
            $peerList[] = ["ip" => $peer["ip"], "port" => $peer["port"]];
        }
    }

    /**
     * @param $queries
     * @throws TrackerException
     */
    private function lockAnnounceDuration($queries)
    {
        $lock_name = "tracker_announce_" . $queries["passkey"] . "_torrent_" . $queries["info_hash"] . "_peer_" . $queries["peer_id"] . "_lock";
        $lock = $this->Redis->get($lock_name);
        if ($lock === false) {
            $this->Redis->setex($lock_name, $this->Config->get("tracker.min_interval"), true);
        } else {
            throw new TrackerException(162, [":min" => $this->Config->get("tracker.min_interval")]);
        }
    }
}