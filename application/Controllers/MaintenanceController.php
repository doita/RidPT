<?php
/**
 * Created by PhpStorm.
 * User: Rhilip
 * Date: 8/11/2019
 * Time: 2019
 */

namespace App\Controllers;

use Rid\Http\AbstractController;

class MaintenanceController extends AbstractController
{
    public function index()
    {
        // Check if site is on maintenance status
        if (!config('base.maintenance')) {
            return container()->get('response')->setRedirect('/index');
        }

        return $this->render('maintenance');
    }
}
