<?php

namespace Icinga\Module\Discourse_notifier\Clicommands;

use DateTime;
use Icinga\Application\Config;
use Icinga\Cli\Command;
use Icinga\Data\ResourceFactory;
use Icinga\Exception\ConfigurationError;
use Icinga\Util\Json;
use PDO;

class CronCommand extends Command
{
    /**
     * @var string[][]
     */
    protected $cfg;

    /**
     * @var int
     */
    protected $now;

    /**
     * @var PDO
     */
    protected $db;
    
    public function indexAction()
    {
        $this->loadConfig([
            'db' => ['resource'],
            'discourse' => ['baseurl', 'apikey']
        ]);

        $this->now = (new DateTime)->getTimestamp();
        $this->connect2Db();
        $this->updateFeed();
        $this->notifyUsers();
    }

    /**
     * @param string[][] $options
     * @throws ConfigurationError
     */
    protected function loadConfig(array $options)
    {
        $config = Config::module('discourse_notifier');
        $result = [];

        foreach ($options as $sectionName => & $section) {
            foreach ($section as $option) {
                $value = $config->get($sectionName, $option);

                if ($value === null) {
                    throw new ConfigurationError(
                        '%s.%s missing in "%s"', $sectionName, $option, $config->getConfigFile()
                    );
                }

                $result[$sectionName][$option] = $value;
            }
        }
        unset($section);

        $this->cfg = $result;
    }

    protected function connect2Db()
    {
        $this->db = ResourceFactory::create($this->cfg['db']['resource'])->getDbAdapter()->getConnection();
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->prepare('SET SESSION TRANSACTION ISOLATION LEVEL SERIALIZABLE;')->execute();
    }

    protected function updateFeed()
    {
        $baseurl = rtrim($this->cfg['discourse']['baseurl'], '/');
        $apikey = $this->cfg['discourse']['apikey'];
        $categories = [];
        $tags = [];

        foreach (Json::decode(file_get_contents(
            "$baseurl/categories.json?api_key=$apikey"
        ))->category_list->categories as $category) {
            $categories[$category->name] = null;
        }

        foreach (Json::decode(file_get_contents("$baseurl/tags.json?api_key=$apikey"))->tags as $tag) {
            $tags[$tag->id] = null;
        }

        $this->db->beginTransaction();

        $select = $this->db->prepare('SELECT 1 FROM discourse_notifier_category WHERE name = ?;');
        $insert = $this->db->prepare('INSERT INTO discourse_notifier_category(name, ctime) VALUES (?, ?);');

        foreach ($categories as $category => $_) {
            $select->execute([$category]);

            if ($select->fetchColumn() === false) {
                $insert->execute([$category, $this->now]);
            }
        }

        $select = $this->db->prepare('SELECT 1 FROM discourse_notifier_tag WHERE name = ?;');
        $insert = $this->db->prepare('INSERT INTO discourse_notifier_tag(name, ctime) VALUES (?, ?);');

        foreach ($tags as $tag => $_) {
            $select->execute([$tag]);

            if ($select->fetchColumn() === false) {
                $insert->execute([$tag, $this->now]);
            }
        }

        $this->db->commit();
    }

    protected function notifyUsers()
    {
        $users = [];
        $news = [];

        $this->db->beginTransaction();

        $select = $this->db->prepare(
            <<<EOD
SELECT u.id, u.email, c.name 
FROM discourse_notifier_user u, discourse_notifier_category c 
WHERE u.last_email < c.ctime;
EOD
        );
        $select->execute();

        foreach ($select->fetchAll(PDO::FETCH_NUM) as list($uid, $email, $category)) {
            $users[$uid] = null;
            $news[$email]['categories'][$category] = null;
        }

        $select = $this->db->prepare(
            <<<EOD
SELECT u.id, u.email, t.name 
FROM discourse_notifier_user u, discourse_notifier_tag t 
WHERE u.last_email < t.ctime;
EOD
        );
        $select->execute();

        foreach ($select->fetchAll(PDO::FETCH_NUM) as list($uid, $email, $tag)) {
            $users[$uid] = null;
            $news[$email]['tags'][$tag] = null;
        }

        foreach ($news as $email => & $newsForUser) {
            $mail = '';

            if (isset($newsForUser['categories'])) {
                $mail .= "\nCategories\n==========\n\n";

                foreach ($newsForUser['categories'] as $category => $_) {
                    $mail .= "* $category\n";
                }
            }

            if (isset($newsForUser['tags'])) {
                $mail .= "\nTags\n==========\n\n";

                foreach ($newsForUser['tags'] as $tag => $_) {
                    $mail .= "* $tag\n";
                }
            }

            $pipe = popen(
                implode(' ', array_map('escapeshellarg', ['mailx', '-s', 'Discourse Notifier', $email])),
                'w'
            );

            fwrite($pipe, $mail);
            fclose($pipe);
        }
        unset($newsForUser);

        $update = $this->db->prepare('UPDATE discourse_notifier_user SET last_email = ? WHERE id = ?;');

        foreach ($users as $uid => $_) {
            $update->execute([$this->now, $uid]);
        }

        $this->db->commit();
    }
}
