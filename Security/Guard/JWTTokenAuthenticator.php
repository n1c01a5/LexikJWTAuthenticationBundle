<?php

namespace Lexik\Bundle\JWTAuthenticationBundle\Security\Guard;

use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTAuthenticatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTFailureEventInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTInvalidEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTNotFoundEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\JWTAuthenticationException;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\JWTDecodeFailure\JWTDecodeFailureException;
use Lexik\Bundle\JWTAuthenticationBundle\Response\JWTAuthenticationFailureResponse;
use Lexik\Bundle\JWTAuthenticationBundle\Security\Authentication\Token\BeforeAuthToken;
use Lexik\Bundle\JWTAuthenticationBundle\Security\Authentication\Token\JWTUserToken;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\TokenExtractor\TokenExtractorInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Guard\AbstractGuardAuthenticator;

/**
 * JsonWebToken Authenticator (Symfony Guard implementation).
 *
 * Thanks @weaverryan for having shown us the way after introduced the component.
 *
 * @see http://knpuniversity.com/screencast/symfony-rest4/jwt-guard-authenticator
 *
 * @author Nicolas Cabot <n.cabot@lexik.fr>
 * @author Robin Chalas <robin.chalas@gmail.com>
 */
class JWTTokenAuthenticator extends AbstractGuardAuthenticator
{
    /**
     * @var JWTManagerInterface
     */
    private $jwtManager;

    /**
     * @var EventDispatcherInterface
     */
    private $dispatcher;

    /**
     * @var TokenExtractorInterface
     */
    private $tokenExtractor;

    /**
     * @var string
     */
    private $userIdentityField;

    /**
     * @param JWTEncoderInterface      $jwtManager
     * @param EventDispatcherInterface $dispatcher
     * @param TokenExtractorInterface  $tokenExtractor
     * @param string                   $userIdentityField
     */
    public function __construct(
        JWTManagerInterface $jwtManager,
        EventDispatcherInterface $dispatcher,
        TokenExtractorInterface $tokenExtractor,
        $userIdentityField
    ) {
        $this->jwtManager        = $jwtManager;
        $this->dispatcher        = $dispatcher;
        $this->tokenExtractor    = $tokenExtractor;
        $this->userIdentityField = $userIdentityField;
    }

    /**
     * Returns a decoded JWT token extracted from a request.
     *
     * {@inheritdoc}
     *
     * @return BeforeAuthToken
     *
     * @throws JWTAuthenticationException If the request token cannot be decoded
     */
    public function getCredentials(Request $request)
    {
        if (false === ($jsonWebToken = $this->tokenExtractor->extract($request))) {
            return;
        }

        $beforeAuthToken = new BeforeAuthToken($jsonWebToken);

        try {
            if (!$payload = $this->jwtManager->decode($beforeAuthToken)) {
                throw JWTAuthenticationException::invalidToken();
            }

            $beforeAuthToken->setPayload($payload);
        } catch (JWTDecodeFailureException $e) {
            throw JWTAuthenticationException::invalidToken($e);
        }

        return $beforeAuthToken;
    }

    /**
     * Returns an user object loaded from a JWT token.
     *
     * {@inheritdoc}
     *
     * @param BeforeAuthToken Implementation of the (Security) TokenInterface
     *
     * @throws JWTAuthenticationException If no user can be loaded from the decoded token
     */
    public function getUser($decodedToken, UserProviderInterface $userProvider)
    {
        $payload  = $decodedToken->getPayload();
        $identity = $this->getUserIdentityFromPayload($payload);

        try {
            $user = $userProvider->loadUserByUsername($identity);
        } catch (UsernameNotFoundException $e) {
            throw JWTAuthenticationException::invalidUser($identity, $this->userIdentityField);
        }

        $authToken = new JWTUserToken($user->getRoles());
        $authToken->setUser($user);
        $authToken->setRawToken($decodedToken->getCredentials());

        $this->dispatcher->dispatch(Events::JWT_AUTHENTICATED, new JWTAuthenticatedEvent($payload, $authToken));

        return $user;
    }

    /**
     * {@inheritdoc}
     *
     * @param JWTFailureEventInterface An event to be dispatched (default JWTInvalidEvent)
     */
    public function onAuthenticationFailure(Request $request, AuthenticationException $authException, JWTFailureEventInterface $event = null)
    {
        if (null === $event) {
            $event = new JWTInvalidEvent($request, $authException, new JWTAuthenticationFailureResponse($authException->getMessage()));
            $this->dispatcher->dispatch(Events::JWT_INVALID, $event);
        }

        return $event->getResponse();
    }

    /**
     * {@inheritdoc}
     */
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, $providerKey)
    {
        return;
    }

    /**
     * {@inheritdoc}
     *
     * @return JWTAuthenticationFailureResponse
     */
    public function start(Request $request, AuthenticationException $authException = null)
    {
        $authException = JWTAuthenticationException::tokenNotFound();
        $event         = new JWTNotFoundEvent($request, $authException, new JWTAuthenticationFailureResponse($authException->getMessage()));

        $this->dispatcher->dispatch(Events::JWT_NOT_FOUND, $event);

        return $this->onAuthenticationFailure($request, $authException, $event);
    }

    /**
     * {@inheritdoc}
     */
    public function checkCredentials($credentials, UserInterface $user)
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsRememberMe()
    {
        return false;
    }

    /**
     * Returns the user identity from a given payload.
     *
     * @param array $payload
     *
     * @return string
     *
     * @throws JWTAuthenticationException If the userIdentityField is not one of the payload keys
     */
    protected function getUserIdentityFromPayload(array $payload)
    {
        if (isset($payload[$this->userIdentityField])) {
            return $payload[$this->userIdentityField];
        }

        throw JWTAuthenticationException::invalidPayload(
            sprintf('Unable to find a key corresponding to the configured user_identity_field ("%s") in the token payload', $this->userIdentityField)
        );
    }
}
