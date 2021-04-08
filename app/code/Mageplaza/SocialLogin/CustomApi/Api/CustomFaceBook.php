<?php
/**
 * Mageplaza
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Mageplaza.com license that is
 * available through the world-wide-web at this URL:
 * https://www.mageplaza.com/LICENSE.txt
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this extension to newer
 * version in the future.
 *
 * @category  Mageplaza
 * @package   Mageplaza_SocialLogin
 * @copyright Copyright (c) Mageplaza (https://www.mageplaza.com/)
 * @license   https://www.mageplaza.com/LICENSE.txt
 */

namespace Mageplaza\SocialLogin\CustomApi\Api;

use Psr\Log\LoggerInterface;
use Mageplaza\SocialLogin\Helper\Social as SocialHelper;
use Mageplaza\SocialLogin\Model\Social;
use Magento\Customer\Model\Customer;
use Magento\Integration\Model\Oauth\TokenFactory as TokenModelFactory;
class CustomFaceBook 
{
    protected $logger;
    
    /**
     * @type SocialHelper
     */
    protected $apiHelper;

    /**
     * @type Social
     */
    protected $apiObject;
    
     /**
     * @var Customer
     */
    protected $customerModel;
    
     /**
     * Token Model
     *
     * @var TokenModelFactory
     */
    private $tokenModelFactory;
    
 
    public function __construct(
        LoggerInterface $logger,
        SocialHelper $apiHelper,
        Social $apiObject,
        Customer $customerModel,
        TokenModelFactory $tokenModelFactory 
    )
    {
 
        $this->logger = $logger;
        $this->typeApi='facebook';
        $this->apiHelper= $apiHelper;
        $this->apiObject= $apiObject;
        $this->customerModel= $customerModel;
        $this->tokenModelFactory = $tokenModelFactory;
       
    }
 
    /**
     * @inheritdoc
     */
 
    public function login($accessToken)
    {
        $response = ['success' => false];
 
        try {
            $this->apiHelper->setConfig('api');
            $type = $this->apiHelper->setType($this->typeApi);
            $userProfile = $this->apiObject->getUserProfile($type);
            if (!$userProfile->identifier) {
                $response = ['success' => false, 'message' => 'Email is Null, Please enter email in your facebook profile'];
            }
            else
            {  
        $customer     = $this->apiObject->getCustomerBySocial($userProfile->identifier, $type);
         $customerData = $this->customerModel->load($customer->getId());
        if (!$customer->getId()) {
            $customer = $this->createCustomerApiProcess($userProfile, $type);
        }
        $customerToken = $this->tokenModelFactory->create();
        $tokenKey = $customerToken->createCustomerToken($customer->getId())->getToken();
            $response = ['success' => true,'id' => $customer->getId(), 'email' => $userProfile->email,'type' => $type,'tokenKey' => $tokenKey];
            }
        } catch (\Exception $e) {
            $response = ['success' => false, 'message' => $e->getMessage()];
            $this->logger->info($e->getMessage());
        }
        $returnResponse = json_encode($response);
        return $returnResponse; 
    }
    
    /**
     * @param $userProfile
     * @param $type
     *
     * @return bool|Customer|mixed
     * @throws Exception
     * @throws LocalizedException
     */
    private function createCustomerApiProcess($userProfile, $type)
    {
        $name = explode(' ', $userProfile->displayName ?: __('New User'));

        $user = array_merge(
            [
                'email'      => $userProfile->email ?: $userProfile->identifier . '@' . strtolower($type) . '.com',
                'firstname'  => $userProfile->firstName ?: (array_shift($name) ?: $userProfile->identifier),
                'lastname'   => $userProfile->lastName ?: (array_shift($name) ?: $userProfile->identifier),
                'identifier' => $userProfile->identifier,
                'type'       => $type,
                'password'   => isset($userProfile->password) ? $userProfile->password : null
            ],
            []
        );

        return $this->createCustomer($user, $type);
    }

    /**
     * Create customer from social data
     *
     * @param $user
     * @param $type
     *
     * @return bool|Customer|mixed
     * @throws Exception
     * @throws LocalizedException
     */
    private function createCustomer($user, $type)
    {
        $customer = $this->apiObject->getCustomerByEmail($user['email'], $this->getStore()->getWebsiteId());
        if ($customer->getId()) {
            $this->apiObject->setAuthorCustomer($user['identifier'], $customer->getId(), $type);
        } else {
            try {
                $customer = $this->apiObject->createCustomerSocial($user, $this->getStore());
            } catch (Exception $e) {
                 throw new Exception($e->getMessage());
                return false;
            }
        }

        return $customer;
    }
}
