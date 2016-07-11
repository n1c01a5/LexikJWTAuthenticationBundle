<?php

namespace Lexik\Bundle\JWTAuthenticationBundle\Tests\Functional\Integration\AppBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\JsonResponse;

class TestController extends Controller
{
    public function securedAction()
    {
        return new JsonResponse(['success' => true]);
    }

    public function loginCheckAction()
    {
        throw new \RuntimeException('loginCheckAction should never be called');
    }
}
