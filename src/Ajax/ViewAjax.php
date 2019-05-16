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

namespace NextDom\Ajax;

use DB;
use NextDom\Enums\UserRight;
use NextDom\Exceptions\CoreException;
use NextDom\Helpers\AjaxHelper;
use NextDom\Helpers\AuthentificationHelper;
use NextDom\Helpers\DBHelper;
use NextDom\Helpers\FileSystemHelper;
use NextDom\Helpers\NextDomHelper;
use NextDom\Helpers\Utils;
use NextDom\Managers\EqLogicManager;
use NextDom\Managers\ViewManager;
use NextDom\Managers\ViewZoneManager;
use NextDom\Model\Entity\View;
use NextDom\Model\Entity\ViewData;
use NextDom\Model\Entity\ViewZone;

class ViewAjax extends BaseAjax
{
    /**
     * @var string
     */
    protected $NEEDED_RIGHTS     = UserRight::USER;
    /**
     * @var bool
     */
    protected $MUST_BE_CONNECTED = true;
    /**
     * @var bool
     */
    protected $CHECK_AJAX_TOKEN = true;

    /**
     * @throws CoreException
     */
    public function remove()
    {
        AuthentificationHelper::isConnectedAsAdminOrFail();
        Utils::unautorizedInDemo();
        $view = ViewManager::byId(Utils::init('id'));
        if (!is_object($view)) {
            throw new CoreException(__('Vue non trouvée. Vérifiez l\'iD'));
        }
        $view->remove();
        AjaxHelper::success();
    }

    /**
     * @throws \ReflectionException
     */
    public function all()
    {
        AjaxHelper::success(Utils::o2a(ViewManager::all()));
    }

    /**
     * @throws CoreException
     */
    public function get()
    {
        if (Utils::init('id') == 'all' || is_json(Utils::init('id'))) {
            $views = [];
            if (is_json(Utils::init('id'))) {
                $view_ajax = json_decode(Utils::init('id'), true);
                foreach ($view_ajax as $id) {
                    $views[] = ViewManager::byId($id);
                }
            } else {
                $views = ViewManager::all();
            }
            $return = array();
            foreach ($views as $view) {
                $return[$view->getId()] = $view->toAjax(Utils::init('version', 'dview'), Utils::init('html'));
            }
            AjaxHelper::success($return);
        } else {
            $view = ViewManager::byId(Utils::init('id'));
            if (!is_object($view)) {
                throw new CoreException(__('Vue non trouvée. Vérifiez l\'ID'));
            }
            AjaxHelper::success($view->toAjax(Utils::init('version', 'dview'), Utils::init('html')));
        }
    }

    /**
     * @throws CoreException
     * @throws \ReflectionException
     */
    public function save()
    {
        AuthentificationHelper::isConnectedAsAdminOrFail();
        Utils::unautorizedInDemo();
        $view = ViewManager::byId(Utils::init('view_id'));
        if (!is_object($view)) {
            $view = new View();
        }
        $view_ajax = json_decode(Utils::init('view'), true);
        Utils::a2o($view, $view_ajax);
        $view->save();
        if (isset($view_ajax['zones']) && count($view_ajax['zones']) > 0) {
            $view->removeviewZone();
            foreach ($view_ajax['zones'] as $viewZone_info) {
                $viewZone = new ViewZone();
                $viewZone->setView_id($view->getId());
                Utils::a2o($viewZone, $viewZone_info);
                $viewZone->save();
                if (isset($viewZone_info['viewData'])) {
                    $order = 0;
                    foreach ($viewZone_info['viewData'] as $viewData_info) {
                        $viewData = new ViewData();
                        $viewData->setviewZone_id($viewZone->getId());
                        $viewData->setOrder($order);
                        Utils::a2o($viewData, NextDomHelper::fromHumanReadable($viewData_info));
                        $viewData->save();
                        $order++;
                    }
                }
            }
        }
        AjaxHelper::success(Utils::o2a($view));
    }

    /**
     * @throws CoreException
     * @throws \ReflectionException
     */
    public function getEqLogicviewZone()
    {
        $viewZone = ViewZoneManager::byId(Utils::init('viewZone_id'));
        if (!is_object($viewZone)) {
            throw new CoreException(__('Vue non trouvée. Vérifiez l\'ID'));
        }
        $return = Utils::o2a($viewZone);
        $return['eqLogic'] = array();
        /**
         * @var ViewData $viewData
         */
        foreach ($viewZone->getViewData() as $viewData) {
            $infoViewDatat = Utils::o2a($viewData->getLinkObject());
            $infoViewDatat['html'] = $viewData->getLinkObject()->toHtml(Utils::init('version'));
            $return['viewData'][] = $infoViewDatat;
        }
        AjaxHelper::success($return);
    }

    /**
     * @throws CoreException
     * @throws \ReflectionException
     */
    public function setEqLogicOrder()
    {
        AuthentificationHelper::isConnectedAsAdminOrFail();
        Utils::unautorizedInDemo();
        $eqLogics = json_decode(Utils::init('eqLogics'), true);
        $sql = '';
        foreach ($eqLogics as $eqLogic_json) {
            if (!isset($eqLogic_json['viewZone_id']) || !is_numeric($eqLogic_json['viewZone_id']) || !is_numeric($eqLogic_json['id']) || !is_numeric($eqLogic_json['order']) || (isset($eqLogic_json['object_id']) && !is_numeric($eqLogic_json['object_id']))) {
                continue;
            }
            $sql .= 'UPDATE viewData SET `order` = ' . $eqLogic_json['order'] . '  WHERE link_id = ' . $eqLogic_json['id'] . ' AND  viewZone_id = ' . $eqLogic_json['viewZone_id'] . ';';
            $eqLogic = EqLogicManager::byId($eqLogic_json['id']);
            if (!is_object($eqLogic)) {
                continue;
            }
            Utils::a2o($eqLogic, $eqLogic_json);
            $eqLogic->save(true);
        }
        if ($sql != '') {
            DBHelper::Prepare($sql, array(), DB::FETCH_TYPE_ROW);
        }
        AjaxHelper::success();
    }

    /**
     * @throws CoreException
     */
    public function setOrder()
    {
        AuthentificationHelper::isConnectedAsAdminOrFail();
        Utils::unautorizedInDemo();
        $order = 1;
        foreach (json_decode(Utils::init('views'), true) as $id) {
            $view = ViewManager::byId($id);
            if (is_object($view)) {
                $view->setOrder($order);
                $view->save();
                $order++;
            }
        }
        AjaxHelper::success();
    }

    /**
     * @throws CoreException
     */
    public function removeImage()
    {
        AuthentificationHelper::isConnectedAsAdminOrFail();
        Utils::unautorizedInDemo();
        $view = ViewManager::byId(Utils::init('id'));
        if (!is_object($view)) {
            throw new CoreException(__('Vue inconnu. Vérifiez l\'ID ') . Utils::init('id'));
        }
        $view->setImage('sha512', '');
        $view->save();
        @rrmdir(NEXTDOM_ROOT . '/public/img/view');
        AjaxHelper::success();
    }

    /**
     * @throws CoreException
     */
    public function uploadImage()
    {
        AuthentificationHelper::isConnectedAsAdminOrFail();
        Utils::unautorizedInDemo();
        $view = ViewManager::byId(Utils::init('id'));
        if (!is_object($view)) {
            throw new CoreException(__('Objet inconnu. Vérifiez l\'ID'));
        }
        if (!isset($_FILES['file'])) {
            throw new CoreException(__('Aucun fichier trouvé. Vérifiez le paramètre PHP (post size limit)'));
        }
        $extension = strtolower(strrchr($_FILES['file']['name'], '.'));
        if (!in_array($extension, array('.jpg', '.png'))) {
            throw new CoreException('Extension du fichier non valide (autorisé .jpg .png) : ' . $extension);
        }
        if (filesize($_FILES['file']['tmp_name']) > 5000000) {
            throw new CoreException(__('Le fichier est trop gros (maximum 5Mo)'));
        }
        $files = FileSystemHelper::ls(NEXTDOM_DATA . '/data/view/', 'view' . $view->getId() . '*');
        if (count($files) > 0) {
            foreach ($files as $file) {
                unlink(NEXTDOM_DATA . '/data/view/' . $file);
            }
        }
        $view->setImage('type', str_replace('.', '', $extension));
        $view->setImage('sha512', sha512(file_get_contents($_FILES['file']['tmp_name'])));
        $filename = 'view' . $view->getId() . '-' . $view->getImage('sha512') . '.' . $view->getImage('type');
        $filepath = NEXTDOM_DATA . '/data/view/' . $filename;
        file_put_contents($filepath, file_get_contents($_FILES['file']['tmp_name']));
        if (!file_exists($filepath)) {
            throw new CoreException(__('Impossible de sauvegarder l\'image', __FILE__));
        }
        $view->save();
        @rrmdir(NEXTDOM_ROOT . '/public/img/view');
        AjaxHelper::success();
    }
}
