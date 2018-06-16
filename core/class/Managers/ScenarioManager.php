<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/* This file is part of NextDom.
 *
 * NextDom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * NextDom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with NextDom. If not, see <http://www.gnu.org/licenses/>.
 */

namespace NextDom\Managers;

//require_once __DIR__.'/../DB.class.php';

// TODO: \DB::buildField(ScenarioEntity::className) à factoriser
class ScenarioManager
{
    const DB_CLASS_NAME = 'scenario';
    const CLASS_NAME = 'scenario';
    const INITIAL_TRANSLATION_FILE = '';

    /**
     * Obtenir un objet scenario
     *
     * @param int $id Identifiant du scénario
     *
     * @return scenario Objet demandé
     *
     * @throws \Exception
     */
    public static function byId($id)
    {
        $values = array('id' => $id);
        $sql = 'SELECT ' . \DB::buildField(ScenarioManager::CLASS_NAME) . ' FROM ' . ScenarioManager::DB_CLASS_NAME . ' WHERE id = :id';
        return \DB::Prepare($sql, $values, \DB::FETCH_TYPE_ROW, \PDO::FETCH_CLASS, ScenarioManager::CLASS_NAME);
    }

    /**
     * Obtenir un objet scenario
     *
     * @param string $scenarioName Chaine identifiant le scénario
     *
     * @return scenario Objet demandé
     *
     * @throws \Exception
     */
    public static function byString($scenarioName, $commandNotFoundString)
    {
        $scenario = self::byId(str_replace('#scenario', '', self::fromHumanReadable($scenarioName)));
        if (!is_object($scenario)) {
            throw new \Exception($commandNotFoundString . $scenarioName . ' => ' . self::fromHumanReadable($scenarioName));
        }
        return $scenario;
    }

    /**
     * Obtenir tous les objets scenario
     *
     * @param string $groupName Filtrer sur un groupe
     * @param string $type Filtrer sur un type
     *
     * @return [scenario] Liste des objets scenario
     */
    public static function all($groupName = '', $type = null)
    {
        $values = array();
        $result1 = null;
        $result2 = null;

        $baseSql = 'SELECT ' . \DB::buildField(ScenarioManager::CLASS_NAME, 's') . 'FROM ' . ScenarioManager::DB_CLASS_NAME . ' s ';
        $sqlWhereTypeFilter = ' ';
        $sqlAndTypeFilter = ' ';
        if ($type !== null) {
            // A aémliorer avec le DAO
            $sqlWhereTypeFilter = ' WHERE `type` = :type ';
            $sqlAndTypeFilter = ' AND `type` = :type ';
            $values['type'] = $type;
        }

        $sql1 = $baseSql . 'INNER JOIN object ob ON s.object_id = ob.id ';
        if ($groupName === '') {
            $sql1 .= $sqlWhereTypeFilter . 'ORDER BY ob.name, s.group, s.name';
            $sql2 = $baseSql . 'WHERE s.object_id IS NULL' . $sqlAndTypeFilter . 'ORDER BY s.group, s.name';
        } elseif ($groupName === null) {
            $sql1 .= 'WHERE (`group` IS NULL OR `group` = "")' . $sqlAndTypeFilter . 'ORDER BY s.group, s.name';
            $sql2 = $baseSql . 'WHERE (`group` IS NULL OR `group` = "") AND s.object_id IS NULL' . $sqlAndTypeFilter . ' ORDER BY s.name';
        } else {
            $values = array('group' => $groupName);
            $sql1 .= 'WHERE `group` = :group ' . $sqlAndTypeFilter . 'ORDER BY ob.name, s.group, s.name';
            $sql2 = $baseSql . 'WHERE `group` = :group AND s.object_id IS NULL' . $sqlAndTypeFilter . 'ORDER BY s.group, s.name';
        }
        $result1 = \DB::Prepare($sql1, $values, \DB::FETCH_TYPE_ALL, \PDO::FETCH_CLASS, ScenarioManager::CLASS_NAME);
        $result2 = \DB::Prepare($sql2, $values, \DB::FETCH_TYPE_ALL, \PDO::FETCH_CLASS, ScenarioManager::CLASS_NAME);
        if (!is_array($result1)) {
            $result1 = array();
        }
        if (!is_array($result2)) {
            $result2 = array();
        }
        return array_merge($result1, $result2);
    }

    /**
     * Obtenir la liste des scénarios planifiés
     *
     * @return [scenario] Liste des scénarios planifiés
     */
    public static function schedule()
    {
        $sql = 'SELECT ' . \DB::buildField(ScenarioManager::CLASS_NAME) . '
                FROM ' . ScenarioManager::DB_CLASS_NAME . '
                WHERE `mode` != "provoke"
                AND isActive = 1';
        return \DB::Prepare($sql, array(), \DB::FETCH_TYPE_ALL, \PDO::FETCH_CLASS, ScenarioManager::CLASS_NAME);
    }

    /**
     *  Obtenir la liste des groupes de scénarios
     *
     * @param string $groupPattern Pattern de recherche
     *
     * @return [] Liste des groupes
     */
    public static function listGroup($groupPattern = null)
    {
        $values = array();
        $sql = 'SELECT DISTINCT(`group`)
        FROM ' . ScenarioManager::DB_CLASS_NAME;
        if ($groupPattern !== null) {
            $values['group'] = '%' . $groupPattern . '%';
            $sql .= ' WHERE `group` LIKE :group';
        }
        $sql .= ' ORDER BY `group`';
        return \DB::Prepare($sql, $values, \DB::FETCH_TYPE_ALL);
    }

    /**
     * Obtenir la liste des scénarios en fonction d'un déclencheur
     *
     * @param string $cmdId Identifiant du déclencheur
     * @param bool $onlyEnabled Filtrer sur les scénarios activés
     *
     * @return [] Liste des scénarios
     */
    public static function byTrigger($cmdId, $onlyEnabled = true)
    {
        $values = array('cmd_id' => '%#' . $cmdId . '#%');
        $sql = 'SELECT ' . \DB::buildField(ScenarioManager::DB_CLASS_NAME) . '
        FROM ' . ScenarioManager::DB_CLASS_NAME . '
        WHERE mode != "schedule" AND `trigger` LIKE :cmd_id';
        if ($onlyEnabled) {
            $sql .= ' AND isActive = 1';
        }
        return \DB::Prepare($sql, $values, \DB::FETCH_TYPE_ALL, \PDO::FETCH_CLASS, ScenarioManager::CLASS_NAME);
    }

    /**
     * Obtenir la liste des scénarios à partir d'un élément TODO: Kesako
     *
     * @param $elementId
     * @return mixed
     */
    public static function byElement($elementId)
    {
        // TODO: Vérifier, bizarre les guillemets dans le like
        $values = array(
            'element_id' => '%"' . $elementId . '"%',
        );
        $sql = 'SELECT ' . \DB::buildField(ScenarioManager::DB_CLASS_NAME) . '
        FROM ' . ScenarioManager::DB_CLASS_NAME . '
        WHERE `scenarioElement` LIKE :element_id';
        return \DB::Prepare($sql, $values, \DB::FETCH_TYPE_ROW, \PDO::FETCH_CLASS, ScenarioManager::CLASS_NAME);
    }

    /**
     * Obtenir un scénario à partir de l'identifiant d'un objet //TODO: Comprendre ce que c'est
     *
     * @param int $objectId Identifiant de l'objet
     * @param bool $onlyEnabled Filtrer uniquement les scénarios activés
     * @param bool $onlyVisible Filtrer uniquement les scénarios visibles
     *
     * @return [] Liste des scénarios
     *
     * @throws \Exception
     */
    public static function byObjectId($objectId, $onlyEnabled = true, $onlyVisible = false)
    {
        $values = array();
        $sql = 'SELECT ' . \DB::buildField(ScenarioManager::CLASS_NAME) . '
        FROM ' . ScenarioManager::DB_CLASS_NAME;
        if ($objectId === null) {
            $sql .= ' WHERE object_id IS NULL';
        } else {
            $values['object_id'] = $objectId;
            $sql .= ' WHERE object_id = :object_id';
        }
        if ($onlyEnabled) {
            $sql .= ' AND isActive = 1';
        }
        if ($onlyVisible) {
            $sql .= ' AND isVisible = 1';
        }
        return \DB::Prepare($sql, $values, \DB::FETCH_TYPE_ALL, \PDO::FETCH_CLASS, ScenarioManager::CLASS_NAME);
    }

    /**
     * Vérifier un scénario
     * TODO: Virer les strings
     *
     * @param event $event Evènement déclencheur
     * @param bool $forceSyncMode Forcer le mode synchrone
     * @param string $forTranslationFile Fichier nécessaire à la traduction
     *
     * @return bool Renvoie toujours true //TODO: A voir
     */
    public static function check($event = null, $forceSyncMode = false, $forTranslationFile)
    {
        $message = '';
        if ($event !== null) {
            $scenarios = [];
            if (is_object($event)) {
                $eventScenarios = self::byTrigger($event->getId());
                $trigger = '#' . $event->getId() . '#';
                $message = __('Scénario exécuté automatiquement sur événement venant de : ', $forTranslationFile) . $event->getHumanName();
            } else {
                $eventScenarios = self::byTrigger($event);
                $trigger = $event;
                $message = __('Scénario exécuté sur événement : #', $forTranslationFile) . $event . '#';
            }
            if (is_array($eventScenarios) && count($eventScenarios) > 0) {
                foreach ($eventScenarios as $scenario) {
                    if ($scenario->testTrigger($trigger)) {
                        $scenarios[] = $scenario;
                    }
                }
            }
        } else {
            $message = __('Scénario exécuté automatiquement sur programmation', $forTranslationFile);
            $scenarios = self::schedule();
            $trigger = 'schedule';
            if (\nextdom::isDateOk()) {
                foreach ($scenarios as $key => &$scenario) {
                    if ($scenario->getState() != 'in progress') {
                        if (!$scenario->isDue()) {
                            unset($scenarios[$key]);
                        }
                    } else {
                        unset($scenarios[$key]);
                    }
                }
            }
        }
        if (count($scenarios) > 0) {
            foreach ($scenarios as $scenario) {
                $scenario->launch($trigger, $message, $forceSyncMode);
            }
        }
        return true;
    }

    /**
     * Contrôle des scénarios // TODO: ???
     *
     * @param string $forTranslationFile Fichier nécessaire à la traduction
     */
    public static function control($forTranslationFile)
    {
        foreach (self::all() as $scenario) {
            if ($scenario->getState() != 'in progress') {
                continue; // TODO: To be or not to be
            }
            if (!$scenario->running()) {
                $scenario->setState('error');
                continue; // TODO: To be or not to be
            }
            $runtime = strtotime('now') - strtotime($scenario->getLastLaunch());
            // TODO: Optimisation
            if (is_numeric($scenario->getTimeout()) && $scenario->getTimeout() != '' && $scenario->getTimeout() != 0 && $runtime > $scenario->getTimeout()) {
                $scenario->stop();
                $scenario->setLog(__('Arret du scénario car il a dépassé son temps de timeout : ', $forTranslationFile) . $scenario->getTimeout() . 's');
                $scenario->persistLog();
            }
        }
    }

    /**
     * Fait dedans ??? TODO: Trouver un nom explicite
     *
     * @param array $options ???
     * @param string $forTranslationFile Fichier nécessaire à la traduction
     *
     * @throws \Exception
     */
    public static function doIn($options, $forTranslationFile)
    {
        $scenario = self::byId($options['scenario_id']);
        if (is_object($scenario)) {
            if ($scenario->getIsActive() == 0) {
                $scenario->setLog(__('Scénario désactivé non lancement de la sous tâche', $forTranslationFile));
                $scenario->persistLog();
            } else {
                $scenarioElement = scenarioElement::byId($options['scenarioElement_id']);
                $scenario->setLog(__('************Lancement sous tâche**************', $forTranslationFile));
                if (isset($options['tags']) && is_array($options['tags']) && count($options['tags']) > 0) {
                    $scenario->setTags($options['tags']);
                    $scenario->setLog(__('Tags : ', $forTranslationFile) . json_encode($scenario->getTags()));
                }
                if (is_object($scenarioElement)) {
                    if (is_numeric($options['second']) && $options['second'] > 0) {
                        sleep($options['second']);
                    }
                    $scenarioElement->getSubElement('do')->execute($scenario);
                    $scenario->setLog(__('************FIN sous tâche**************', $forTranslationFile));
                    $scenario->persistLog();
                }
            }
        }
    }

    /**
     * Nettoie la table TODO: Avec l'éponge et grosse optimisation à faire
     */
    public static function cleanTable()
    {
        $ids = array(
            'element' => array(),
            'subelement' => array(),
            'expression' => array(),
        );
        foreach (self::all() as $scenario) {
            foreach ($scenario->getElement() as $element) {
                $result = $element->getAllId();
                $ids['element'] = array_merge($ids['element'], $result['element']);
                $ids['subelement'] = array_merge($ids['subelement'], $result['subelement']);
                $ids['expression'] = array_merge($ids['expression'], $result['expression']);
            }
        }

        $tablesToClean = [
            'scenarioExpression' => 'expression',
            'scenarioSubElement' => 'subelement',
            'scenarioElement' => 'element'
        ];

        foreach ($tablesToClean as $table => $item) {
            $sql = 'DELETE FROM ' . $table . ' WHERE id NOT IN (-1';
            foreach ($ids[$item] as $expressionId) {
                $sql .= ',' . $expressionId;
            }
            $sql .= ')';
            \DB::Prepare($sql, array(), \DB::FETCH_TYPE_ALL);
        }
    }

    /**
     * Test la validité des scénarios TODO: Je suppose
     *
     * @param bool $needsReturn Argument à virer
     * @param string $forTranslationFile Fichier nécessaire à la traduction
     *
     * @return array
     */
    public static function consystencyCheck($needsReturn = false, $forTranslationFile)
    {
        $return = array();
        foreach (self::all() as $scenario) {
            if ($scenario->getIsActive() != 1) {
                if (!$needsReturn) {
                    continue;
                }
            }
            if ($scenario->getMode() == 'provoke' || $scenario->getMode() == 'all') {
                $trigger_list = '';
                foreach ($scenario->getTrigger() as $trigger) {
                    $trigger_list .= \cmd::cmdToHumanReadable($trigger) . '_';
                }
                preg_match_all("/#([0-9]*)#/", $trigger_list, $matches);
                foreach ($matches[1] as $cmd_id) {
                    if (is_numeric($cmd_id)) {
                        if ($needsReturn) {
                            $return[] = array('detail' => 'Scénario ' . $scenario->getHumanName(), 'help' => 'Déclencheur du scénario', 'who' => '#' . $cmd_id . '#');
                        } else {
                            \log::add('scenario', 'error', __('Un déclencheur du scénario : ', $forTranslationFile) . $scenario->getHumanName() . __(' est introuvable', $forTranslationFile));
                        }
                    }
                }
            }
            $expression_list = '';
            foreach ($scenario->getElement() as $element) {
                $expression_list .= \cmd::cmdToHumanReadable(json_encode($element->getAjaxElement()));
            }
            preg_match_all("/#([0-9]*)#/", $expression_list, $matches);
            foreach ($matches[1] as $cmd_id) {
                if (is_numeric($cmd_id)) {
                    if ($needsReturn) {
                        $return[] = array('detail' => 'Scénario ' . $scenario->getHumanName(), 'help' => 'Utilisé dans le scénario', 'who' => '#' . $cmd_id . '#');
                    } else {
                        \log::add('scenario', 'error', __('Une commande du scénario : ', $forTranslationFile) . $scenario->getHumanName() . __(' est introuvable', $forTranslationFile));
                    }
                }
            }
        }
        if ($needsReturn) {
            return $return;
        }
    }

    /**
     * Liste des scénario triés par objet, group et nom du scénario
     *
     * @param $objectName
     * @param $groupName
     * @param $scenarioName
     * @param string $forTranslationFile Fichier nécessaire à la traduction
     *
     * @return mixed
     */
    public static function byObjectNameGroupNameScenarioName($objectName, $groupName, $scenarioName, $forTranslationFile)
    {
        $values = array(
            'scenario_name' => html_entity_decode($scenarioName),
        );

        $sql = 'SELECT ' . \DB::buildField(ScenarioManager::CLASS_NAME, 's') . '
                FROM ' . ScenarioManager::DB_CLASS_NAME . ' s ';

        if ($objectName == __('Aucun', $forTranslationFile)) {
            $sql .= 'WHERE s.name=:scenario_name ';
            if ($groupName == __('Aucun', $forTranslationFile)) {
                $sql .= 'AND (`group` IS NULL OR `group` = ""  OR `group` = "Aucun" OR `group` = "None")
                         AND s.object_id IS NULL';
            } else {
                $values['group_name'] = $groupName;
                $sql .= 'AND s.object_id IS NULL
                         AND `group` = :group_name';
            }
        } else {
            $values['object_name'] = $objectName;
            $sql .= 'INNER JOIN object ob ON s.object_id=ob.id
                     WHERE s.name = :scenario_name
                     AND ob.name = :object_name ';
            if ($groupName == __('Aucun', $forTranslationFile)) {
                $sql .= 'AND (`group` IS NULL OR `group` = ""  OR `group` = "Aucun" OR `group` = "None")';
            } else {
                $values['group_name'] = $groupName;
                $sql .= 'AND `group` = :group_name';
            }
        }
        return \DB::Prepare($sql, $values, \DB::FETCH_TYPE_ROW, \PDO::FETCH_CLASS, ScenarioManager::CLASS_NAME);
    }

    /**
     * /TODO: Fatigué d'essayer de comprendre à quoi ça sert
     * Méthode appelée de façon recursive
     *
     * @param $input /TODO: Ca entre en effect
     *
     * @return string TODO: Quelque chose de lisible à priori
     *
     * @throws \Exception
     */
    public static function toHumanReadable($input)
    {
        if (is_object($input)) {
            $reflections = array();
            $uuid = spl_object_hash($input);
            if (!isset($reflections[$uuid])) {
                $reflections[$uuid] = new ReflectionClass($input);
            }
            $reflection = $reflections[$uuid];
            $properties = $reflection->getProperties();
            foreach ($properties as $property) {
                $property->setAccessible(true);
                $value = $property->getValue($input);
                $property->setValue($input, self::toHumanReadable($value));
                $property->setAccessible(false);
            }
            return $input;
        }
        if (is_array($input)) {
            foreach ($input as $key => $value) {
                $input[$key] = self::toHumanReadable($value);
            }
            return $input;
        }
        $text = $input;
        preg_match_all("/#scenario([0-9]*)#/", $text, $matches);
        foreach ($matches[1] as $scenario_id) {
            if (is_numeric($scenario_id)) {
                $scenario = self::byId($scenario_id);
                if (is_object($scenario)) {
                    $text = str_replace('#scenario' . $scenario_id . '#', '#' . $scenario->getHumanName(true) . '#', $text);
                }
            }
        }
        return $text;
    }

    /**
     * TODO: Ca fait l'inverse, mais je sais pas quoi
     *
     * @param $input
     * @return array|mixed
     */
    public static function fromHumanReadable($input)
    {
        $isJson = false;
        if (is_json($input)) {
            $isJson = true;
            $input = json_decode($input, true);
        }
        if (is_object($input)) {
            $reflections = array();
            $uuid = spl_object_hash($input);
            if (!isset($reflections[$uuid])) {
                $reflections[$uuid] = new ReflectionClass($input);
            }
            $reflection = $reflections[$uuid];
            $properties = $reflection->getProperties();
            foreach ($properties as $property) {
                $property->setAccessible(true);
                $value = $property->getValue($input);
                $property->setValue($input, self::fromHumanReadable($value));
                $property->setAccessible(false);
            }
            return $input;
        }
        if (is_array($input)) {
            foreach ($input as $key => $value) {
                $input[$key] = self::fromHumanReadable($value);
            }
            if ($isJson) {
                return json_encode($input, JSON_UNESCAPED_UNICODE);
            }
            return $input;
        }
        $text = $input;

        preg_match_all("/#\[(.*?)\]\[(.*?)\]\[(.*?)\]#/", $text, $matches);
        if (count($matches) == 4) {
            $countMatches = count($matches[0]);
            for ($i = 0; $i < $countMatches; $i++) {
                if (isset($matches[1][$i]) && isset($matches[2][$i]) && isset($matches[3][$i])) {
                    $scenario = self::byObjectNameGroupNameScenarioName($matches[1][$i], $matches[2][$i], $matches[3][$i]);
                    if (is_object($scenario)) {
                        $text = str_replace($matches[0][$i], '#scenario' . $scenario->getId() . '#', $text);
                    }
                }
            }
        }

        return $text;
    }

    /**
     * TODO:
     * @param $searchs
     * @return array
     */
    public static function searchByUse($searchs)
    {
        $return = array();
        $expressions = array();
        $scenarios = array();
        foreach ($searchs as $search) {
            $_cmd_id = str_replace('#', '', $search['action']);
            $return = array_merge($return, self::byTrigger($_cmd_id, false));
            if (!isset($search['and'])) {
                $search['and'] = false;
            }
            if (!isset($search['option'])) {
                $search['option'] = $search['action'];
            }
            $expressions = array_merge($expressions, \scenarioExpression::searchExpression($search['action'], $search['option'], $search['and']));
        }
        if (is_array($expressions) && count($expressions) > 0) {
            foreach ($expressions as $expression) {
                $scenarios[] = $expression->getSubElement()->getElement()->getScenario();
            }
        }
        if (is_array($scenarios) && count($scenarios) > 0) {
            foreach ($scenarios as $scenario) {
                if (is_object($scenario)) {
                    $find = false;
                    foreach ($return as $existScenario) {
                        if ($scenario->getId() == $existScenario->getId()) {
                            $find = true;
                            break;
                        }
                    }
                    if (!$find) {
                        $return[] = $scenario;
                    }
                }
            }
        }
        return $return;
    }

    /**
     *TODO: PATH pointe vers rien
     *
     * @param string $template
     *
     * @return type
     */
    public static function getTemplate($template = '')
    {
        $path = dirname(__FILE__) . '/../../config/scenario';
        if (isset($template) && $template != '') {
            // TODO Magic trixxxxxx
        }
        return ls($path, '*.json', false, array('files', 'quiet'));
    }

    /**
     * TODO:
     *
     * @param $market
     * @param string $forTranslationFile Fichier nécessaire à la traduction
     *
     * @return string
     *
     * @throws \Exception
     */
    public static function shareOnMarket(&$market, $forTranslationFile)
    {
        $moduleFile = dirname(__FILE__) . '/../../config/scenario/' . $market->getLogicalId() . '.json';
        if (!file_exists($moduleFile)) {
            throw new \Exception('Impossible de trouver le fichier de configuration ' . $moduleFile);
        }
        $tmp = \nextdom::getTmpFolder('market') . '/' . $market->getLogicalId() . '.zip';
        if (file_exists($tmp)) {
            if (!unlink($tmp)) {
                throw new \Exception(__('Impossible de supprimer : ', $forTranslationFile) . $tmp . __('. Vérifiez les droits', $forTranslationFile));
            }
        }
        if (!\create_zip($moduleFile, $tmp)) {
            throw new \Exception(__('Echec de création du zip. Répertoire source : ', $forTranslationFile) . $moduleFile . __(' / Répertoire cible : ', $forTranslationFile) . $tmp);
        }
        return $tmp;
    }

    /**
     * TODO:
     * @param type $market
     * @param type $path
     * @param string $forTranslationFile Fichier nécessaire à la traduction
     * @throws Exception
     */
    public static function getFromMarket(&$market, $path, $forTranslationFile)
    {
        $cibDir = __DIR__ . '/../../config/scenario/';
        if (!file_exists($cibDir)) {
            mkdir($cibDir);
        }
        $zip = new \ZipArchive;
        if ($zip->open($path) === true) {
            $zip->extractTo($cibDir . '/');
            $zip->close();
        } else {
            throw new \Exception('Impossible de décompresser l\'archive zip : ' . $forTranslationFile);
        }
    }

    /**
     * TODO: Trixxxxxx
     * @return array
     */
    public static function listMarketObject()
    {
        return array();
    }

    /**
     * TODO: Le CSS C'est pour les faibles
     * @param $event
     * @return array|null
     */
    public static function timelineDisplay($event)
    {
        $return = array();
        $return['date'] = $event['datetime'];
        $return['group'] = 'scenario';
        $return['type'] = $event['type'];
        $scenario = \scenario::byId($event['id']);
        if (!is_object($scenario)) {
            return null;
        }
        $object = $scenario->getObject();
        $return['object'] = is_object($object) ? $object->getId() : 'aucun';
        $return['html'] = '<div class="scenario" data-id="' . $event['id'] . '">'
            . '<div style="background-color:#e7e7e7;padding:1px;font-size:0.9em;font-weight: bold;cursor:help;">' . $event['name'] .
            ' <i class="fa fa-file-text-o pull-right cursor bt_scenarioLog"></i> <i class="fa fa-share pull-right cursor bt_gotoScenario"></i></div>'
            . '<div style="background-color:white;padding:1px;font-size:0.8em;cursor:default;">Déclenché par ' . $event['trigger'] . '<div/>'
            . '</div>';
        return $return;
    }
}