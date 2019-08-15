<?php
/** TODO
 * Created by PhpStorm.
 * User: Rhilip
 * Date: 8/7/2019
 * Time: 10:03 PM
 */

namespace apps\models\form\Subtitles;

use apps\libraries\Constant;

use Rid\Validators\Pager;

class SearchForm extends Pager
{

    public $search;
    public $letter;

    public $torrent_id;
    public $tid;

    protected $_autoload = true;
    protected $_autoload_from = ['get'];

    public static function inputRules(): array
    {
        return [
            'tid' => 'Integer',
            'letter' => 'Alpha | MaxLength(max=1)'
            // 'page' => 'Integer', 'limit' => 'Integer'
        ];
    }

    protected function getRemoteTotal(): int
    {
        $search = $this->getInput('search');
        $letter = $this->getInput('letter');
        $tid = $this->getInput('torrent_id') ?? $this->getInput('tid');
        return app()->pdo->createCommand([
            ['SELECT COUNT(`id`) FROM `subtitles` WHERE 1=1 '],
            ['AND torrent_id = :tid ', 'if' => !is_null($tid), 'params' => ['tid' => $tid]],
            ['AND title LIKE :search ', 'if' => !is_null($search) , 'params' => ['search' => "%$search%"]],
            ['AND title LIKE :letter ', 'if' => is_null($search) && !is_null($letter) , 'params' => ['letter' => "$letter%"]],
        ])->queryScalar();  // TODO
    }

    protected function getRemoteData(): array
    {
        $search = $this->search;
        $letter = $this->letter;
        $tid = $this->torrent_id ?? $this->tid;
        return app()->pdo->createCommand([
            ['SELECT * FROM `subtitles` WHERE 1=1 '],
            ['AND torrent_id = :tid ', 'if' => !is_null($tid), 'params' => ['tid' => $tid]],
            ['AND title LIKE :search ', 'if' => !is_null($search) , 'params' => ['search' => "%$search%"]],
            ['AND title LIKE :letter ', 'if' => is_null($search) && !is_null($letter) , 'params' => ['letter' => "$letter%"]],
            ['ORDER BY id DESC '],
            ['LIMIT :offset, :rows', 'params' => ['offset' => $this->offset, 'rows' => $this->limit]],
        ])->queryAll();
    }

    public function getSubsSizeSum()
    {
        if (false === $size = app()->redis->get(Constant::siteSubtitleSize)) {
            $size = app()->pdo->createCommand('SELECT SUM(`size`) FROM `subtitles`')->queryScalar();
            app()->redis->set(Constant::siteSubtitleSize, $size);
        }
        return $size;
    }

}