<?php

namespace NetworkInternational\NGenius\Controller\NGeniusOnline;

use Exception;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\View\LayoutFactory;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Model\Order;
use NetworkInternational\NGenius\Block\Ngenius;
use NetworkInternational\NGenius\Gateway\Config\Config;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Class Redirect
 *
 * The Redirect Controller responsible for sending the customer to the NGenius Payment Page
 */
class Redirect implements HttpGetActionInterface
{
    protected const CARTPATH = "checkout/cart";
    /**
     * @var OrderRepositoryInterface
     * Repository for managing order entities.
     */
    private OrderRepositoryInterface $orderRepository;
    /**
     * @var ResultFactory
     */
    protected ResultFactory $resultRedirect;

    /**
     * @var Session
     */
    protected Session $checkoutSession;

    /**
     * @var LayoutFactory
     */
    protected LayoutFactory $layoutFactory;
    /**
     * @var CartRepositoryInterface
     */
    private CartRepositoryInterface $quoteRepository;
    /**
     * @var ManagerInterface
     */
    private ManagerInterface $messageManager;
    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;
    /**
     * @var Config
     */
    private Config $config;

    /**
     * Redirect constructor.
     *
     * @param ResultFactory $resultRedirect Factory for creating result instances.
     * @param Session $checkoutSession Checkout session model.
     * @param LayoutFactory $layoutFactory Factory for creating layout instances.
     * @param CartRepositoryInterface $quoteRepository Repository for managing cart quotes.
     * @param ManagerInterface $messageManager Interface for managing messages.
     * @param ScopeConfigInterface $scopeConfig Interface for accessing scope configuration.
     * @param Config $config Configuration for NGenius payment gateway.
     * @param OrderRepositoryInterface $orderRepository Repository for managing order entities.
     */
    public function __construct(
        ResultFactory $resultRedirect,
        Session $checkoutSession,
        LayoutFactory $layoutFactory,
        CartRepositoryInterface $quoteRepository,
        ManagerInterface $messageManager,
        ScopeConfigInterface $scopeConfig,
        Config $config,
        OrderRepositoryInterface $orderRepository,
    ) {
        $this->resultRedirect  = $resultRedirect;
        $this->checkoutSession = $checkoutSession;
        $this->layoutFactory   = $layoutFactory;
        $this->quoteRepository = $quoteRepository;
        $this->messageManager  = $messageManager;
        $this->scopeConfig     = $scopeConfig;
        $this->config          = $config;
        $this->orderRepository = $orderRepository;
    }

    /**
     * Redirects to ngenius payment portal
     *
     * @return ResultInterface
     * @throws NoSuchEntityException
     */
    public function execute(): ResultInterface
    {
        $order = $this->checkoutSession->getLastRealOrder();

        $storeId = $order->getStoreId();

        $ngeniusPaymentAction = $this->config->getPaymentAction($storeId);

        $url = [];
        try {
            $block = $this->layoutFactory->create()->createBlock(Ngenius::class);
            $url   = $block->getPaymentUrl($ngeniusPaymentAction);
        } catch (Exception $exception) {
            $url['exception'] = $exception;
        }

        $resultRedirectFactory = $this->resultRedirect->create(ResultFactory::TYPE_REDIRECT);
        $initialStatus         = $this->config->getInitialOrderStatus($storeId);
        $order                 = $this->checkoutSession->getLastRealOrder();
        $order->setState($initialStatus);
        $order->setStatus($initialStatus);
        $order->addCommentToStatusHistory(
            __('Set configured "Status of new order".')
        );
        if (isset($url['url'])) {
            $resultRedirectFactory->setUrl($url['url']);
        } else {
            $exception = $url['exception'];
            $this->messageManager->addExceptionMessage($exception, $exception->getMessage());
            $resultRedirectFactory->setPath(self::CARTPATH);
            $order = $this->checkoutSession->getLastRealOrder();
            $order->addCommentToStatusHistory($exception->getMessage());
            $order->setStatus('ngenius_failed');
            $order->setState(Order::STATE_CLOSED);
            $this->orderRepository->save($order);
            $this->restoreQuote();
        }

        return $resultRedirectFactory;
    }

    /**
     * Cart restore
     *
     * @throws NoSuchEntityException
     */
    public function restoreQuote()
    {
        $session = $this->checkoutSession;
        $order   = $session->getLastRealOrder();
        $quoteId = $order->getQuoteId();
        $quote   = $this->quoteRepository->get($quoteId);
        $quote->setIsActive(1)->setReservedOrderId(null);
        $this->quoteRepository->save($quote);
        $session->replaceQuote($quote)->unsLastRealOrderId();
    }
}
