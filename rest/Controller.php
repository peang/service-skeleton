<?php
namespace peang\base\rest;

use Slim\Http\Request;

/**
 * This will be base Rest controller
 * @package base\rest
 * @author  Irvan Setiawan <peang.cookie@gmail.com>
 */
class Controller
{
    /**
     * @param Request $request
     *
     * @return mixed
     */
    public function getContents(Request $request)
    {
        return json_decode($request->getBody()->getContents(), true);
    }
}