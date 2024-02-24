<?php
/**
 * @version    2.11.x
 * @package    K2
 * @author     JoomlaWorks https://www.joomlaworks.net
 * @copyright  Copyright (c) 2006 - 2022 JoomlaWorks Ltd. All rights reserved.
 * @license    GNU/GPL license: https://www.gnu.org/copyleft/gpl.html
 */

// no direct access
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Session\Session;

jimport('joomla.application.component.controller');

class K2ControllerExtraFieldsGroups extends K2Controller
{
    public function display($cachable = false, $urlparams = array())
    {
        Factory::getApplication()->input->set('view', 'extrafieldsgroups');
        $model = $this->getModel('extraFields');
        $view = $this->getView('extrafieldsgroups', 'html');
        $view->setModel($model, true);
        parent::display();
    }

    public function add()
    {
        $app = Factory::getApplication();
        $app->redirect('index.php?option=com_k2&view=extrafieldsgroup');
    }

    public function edit()
    {
        $app = Factory::getApplication();
        $cid = Factory::getApplication()->input->getVar('cid');
        $app->redirect('index.php?option=com_k2&view=extrafieldsgroup&cid=' . $cid[0]);
    }

    public function remove()
    {
        /* since J4 compatibility */;
        Session::checkToken() or jexit('Invalid Token');
        $model = $this->getModel('extraFields');
        $model->removeGroups();
    }
}
