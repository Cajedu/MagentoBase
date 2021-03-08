<?php
/**
 * ScandiPWA - Progressive Web App for Magento
 *
 * Copyright © Scandiweb, Inc. All rights reserved.
 * See LICENSE for license details.
 *
 * @license OSL-3.0 (Open Software License ("OSL") v. 3.0)
 * @package scandipwa/wishlist-graphql
 * @link    https://github.com/scandipwa/wishlist-graphql
 */

declare(strict_types=1);

namespace ScandiPWA\WishlistGraphQl\Model\Resolver;

use Exception;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\ResourceModel\CustomerRepository;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\UrlInterface;
use Magento\Framework\Validator\EmailAddress;
use Magento\Framework\View\LayoutFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Wishlist\Model\WishlistFactory;
use Zend_Validate;

/**
 * Class ShareWishlist
 * @package ScandiPWA\WishlistGraphQl\Model\Resolver
 */
class ShareWishlist implements ResolverInterface
{
    /**
     * @var CustomerRepository
     */
    protected $customerRepository;
    /**
     * @var Escaper
     */
    protected $escaper;
    /**
     * @var WishlistFactory
     */
    protected $wishlistFactory;
    /**
     * @var WishlistResolver
     */
    protected $wishlistResolver;
    /**
     * @var TransportBuilder
     */
    protected $transportBuilder;
    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;
    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;
    /**
     * @var UrlInterface
     */
    protected $url;
    /**
     * @var LayoutFactory
     */
    protected $layoutFactory;

    /**
     * @param Escaper $escaper
     * @param UrlInterface $url
     * @param LayoutFactory $layoutFactory
     * @param WishlistFactory $wishlistFactory
     * @param ScopeConfigInterface $scopeConfig
     * @param WishlistResolver $wishlistResolver
     * @param TransportBuilder $transportBuilder
     * @param StoreManagerInterface $storeManager
     * @param CustomerRepository $customerRepository
     */
    public function __construct(
        Escaper $escaper,
        UrlInterface $url,
        LayoutFactory $layoutFactory,
        WishlistFactory $wishlistFactory,
        ScopeConfigInterface $scopeConfig,
        WishlistResolver $wishlistResolver,
        TransportBuilder $transportBuilder,
        StoreManagerInterface $storeManager,
        CustomerRepository $customerRepository
    )
    {
        $this->url = $url;
        $this->escaper = $escaper;
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        $this->layoutFactory = $layoutFactory;
        $this->wishlistFactory = $wishlistFactory;
        $this->wishlistResolver = $wishlistResolver;
        $this->transportBuilder = $transportBuilder;
        $this->customerRepository = $customerRepository;
    }

    /**
     * @inheritDoc
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        array $value = null,
        array $args = null
    ): bool
    {
        $customerId = $context->getUserId();
        if (!$customerId) {
            throw new GraphQlAuthorizationException(__('Authorization unsuccessful'));
        }

        $emails = $args['input']['emails'] ?? null;
        $message = $args['input']['message'] ?? '';

        $wishlist = $this->wishlistFactory->create();
        $this->wishlistResolver->loadWishlist($wishlist, null, $context);

        $message = nl2br($this->escaper->escapeHtml($message));

        /** @var CustomerInterface */
        $customer = $this->customerRepository->getById($customerId);

        $firstName = $customer->getFirstname();
        $lastName = $customer->getLastname();

        $sent = 0;
        $customerName = "$firstName $lastName";
        $sharingCode = $wishlist->getSharingCode();

        try {
            $sentEmails = [];

            foreach ($emails as $email) {
                $email = trim($email);

                if (in_array($email, $sentEmails, true)) {
                    continue;
                }

                if (!Zend_Validate::is($email, EmailAddress::class)) {
                    throw new GraphQlInputException(__('Provided emails are not valid'));
                }

                $transport = $this->transportBuilder->setTemplateIdentifier(
                    $this->scopeConfig->getValue(
                        'wishlist/email/email_template',
                        ScopeInterface::SCOPE_STORE
                    )
                )->setTemplateOptions(
                    [
                        'area' => Area::AREA_FRONTEND,
                        'store' => $this->storeManager->getStore()->getStoreId(),
                    ]
                )->setTemplateVars(
                    [
                        'customer' => $customer,
                        'customerName' => $customerName,
                        'salable' => $wishlist->isSalable() ? 'yes' : '',
                        'items' => $this->getWishlistItems(),
                        'viewOnSiteLink' => $this->getWebsiteLink($sharingCode),
                        'message' => $message,
                        'store' => $this->storeManager->getStore(),
                    ]
                )->setFromByScope(
                    [
                        'name' => $customerName,
                        'email' => $customer->getEmail(),
                    ],
                    ScopeInterface::SCOPE_STORE
                )->addTo(
                    $email
                )->getTransport();

                $transport->sendMessage();

                $sent++;
                $sentEmails[] = $email;
            }
        } catch (Exception $e) {
            $wishlist->setShared($wishlist->getShared() + $sent);
            $wishlist->save();
            throw $e;
        }

        $wishlist->setShared($wishlist->getShared() + $sent);
        $wishlist->save();

        return true;
    }

    /**
     * Retrieve wishlist items content (html)
     *
     * @return string
     * @throws LocalizedException
     */
    protected function getWishlistItems(): string
    {

        $layout = $this->layoutFactory->create();
        $layout->getUpdate()->load(['wishlist_email_items']);
        $layout->generateXml();
        $layout->generateElements();

        $block = $layout->getBlock('wishlist.email.items');

        return $block ? $block->getHtml() : '';
    }

    protected function getWebsiteLink(string $sharingCode): string
    {
        $baseUrl = $this->url->getBaseUrl();
        return $baseUrl . "wishlist/shared/". $sharingCode;
    }
}
