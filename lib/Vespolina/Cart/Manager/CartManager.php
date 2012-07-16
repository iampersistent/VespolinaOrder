<?php
/**
 * (c) 2011-2012 Vespolina Project http://www.vespolina-project.org
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Vespolina\Cart\Manager;

use Vespolina\Cart\Event\CartEvents;
use Vespolina\Cart\Event\CartEvent;
use Vespolina\Cart\Event\CartPricingEvent;
use Vespolina\Cart\Manager\CartManagerInterface;
use Vespolina\Cart\Pricing\CartPricingProviderInterface;
use Vespolina\Cart\Pricing\PricingSetInterface;
use Vespolina\Entity\Order\Cart;
use Vespolina\Entity\Order\CartInterface;
use Vespolina\Entity\Order\ItemInterface;
use Vespolina\Entity\Order\OrderInterface;
use Vespolina\Entity\ProductInterface;
use Vespolina\EventDispatcher\NullDispatcher;

/**
 * @author Daniel Kucharski <daniel@xerias.be>
 * @author Richard Shank <develop@zestic.com>
 */
class CartManager implements CartManagerInterface
{
    protected $cartClass;
    protected $cartItemClass;
    protected $eventDispatcher;
    protected $pricingProvider;

    function __construct(CartPricingProviderInterface $pricingProvider, $cartClass, $cartItemClass, EventDispatcherInterface $eventDispatcher = null)
    {
        if (!$eventDispatcher) {
            $eventDispatcher = new NullDispatcher();
        }
        $this->cartClass = $cartClass;
        $this->cartItemClass = $cartItemClass;
        $this->eventDispatcher = $eventDispatcher;
        $this->pricingProvider = $pricingProvider;
    }

    /**
     * @inheritdoc
     */
    public function addProductToCart(CartInterface $cart, ProductInterface $product, array $options = null, $orderedQuantity = null)
    {
        $item = $this->doAddItemToCart($cart, $product);

        return $item;
    }

    /**
     * @inheritdoc
     */
    public function createCart($cartType = 'default')
    {
        $cart = new $this->cartClass($cartType);
        $this->initCart($cart);

        return $cart;
    }

    /**
     * @inheritdoc
     */
    public function determinePrices(CartInterface $cart, $determineItemPrices = true)
    {
        $pricingProvider = $this->getPricingProvider();
        $pricingContext = $pricingProvider->createPricingContext();

        //Init the pricing context container and have it filled if required through the event dispatcher
        $this->eventDispatcher->dispatch(CartEvents::INIT_PRICING_CONTEXT, new CartPricingEvent($cart, $pricingContext));

        $pricingProvider->determineCartPrices($cart, $pricingContext, $determineItemPrices);
    }

    /**
     * @inheritdoc
     */
    public function findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
    {
        throw new \Exception('gateway implementation needed');
    }

    /**
     * @inheritdoc
     */
    public function findCartById($id)
    {
        throw new \Exception('gateway implementation needed');
    }

    /**
     * @inheritdoc
     */
    public function getPricingProvider()
    {
        return $this->pricingProvider;
    }

    /**
     * @inheritdoc
     */
    public function setCartPricingSet(CartInterface $cart, PricingSetInterface $pricingSet)
    {
        $rp = new \ReflectionProperty($cart, 'pricingSet');
        $rp->setAccessible(true);
        $rp->setValue($cart, $pricingSet);
        $rp->setAccessible(false);
    }

    /**
     * @inheritdoc
     */
    public function findProductInCart(CartInterface $cart, ProductInterface $product)
    {
        foreach ($cart->getItems() as $item) {
            if ($item->getProduct() == $product) {
                return $item;
            };
        }

        return null;
    }

    /**
     * @inheritdoc
     */
    public function removeProductFromCart(CartInterface $cart, ProductInterface $product, $flush = true)
    {
        $this->doRemoveItemFromCart($cart, $product);
    }

    /**
     * @inheritdoc
     */
    public function setCartItemState(ItemInterface $cartItem, $state)
    {
        $rp = new \ReflectionProperty($cartItem, 'state');
        $rp->setAccessible(true);
        $rp->setValue($cartItem, $state);
        $rp->setAccessible(false);
    }

    /**
     * @inheritdoc
     */
    public function setCartState(CartInterface $cart, $state)
    {
        $rp = new \ReflectionProperty($cart, 'state');
        $rp->setAccessible(true);
        $rp->setValue($cart, $state);
        $rp->setAccessible(false);
    }

    /**
     * @inheritdoc
     */
    public function setProductQuantity(CartInterface $cart, ProductInterface $product, $quantity)
    {
        // add item to cart
        $rm = new \ReflectionMethod($cartItem, 'setQuantity');
        $rm->setAccessible(true);
        $rm->invokeArgs($cartItem, array($quantity));
        $rm->setAccessible(false);
    }

    /**
     * @inheritdoc
     */
    public function updateCart(CartInterface $cart, $andPersist = true)
    {
        $this->eventDispatcher->dispatch(CartEvents::FINISHED, new CartEvent($cart));
        // gateway->persist();
    }

    protected function createItem(ProductInterface $product = null)
    {
        $cartItem = new $this->cartItemClass($product);
        $this->initCartItem($cartItem);

        return $cartItem;
    }

    protected function initCart(CartInterface $cart)
    {
        // Create the pricing set to hold cart level pricing data
        $this->setCartPricingSet($cart, $this->pricingProvider->createPricingSet());

        // Set default state (for now we set it to "open"), do this last since it will persist and flush the cart
        $this->setCartState($cart, Cart::STATE_OPEN);

        //Delegate further initialization of the cart to those concerned
        $this->eventDispatcher->dispatch(CartEvents::CART_INIT,  new CartEvent($cart));
    }

    protected function doAddItemToCart(CartInterface $cart, ProductInterface $product)
    {
        if ($cartItem = $this->findItemInCart($cart, $product)) {
            $quantity = $cartItem->getQuantity() + 1;
            $this->setItemQuantity($cartItem, $quantity);

            return $cartItem;
        }

        $item = $this->createItem($product);

        // add item to cart
        $rm = new \ReflectionMethod($cart, 'addItem');
        $rm->setAccessible(true);
        $rm->invokeArgs($cart, array($item));
        $rm->setAccessible(false);

        return $item;
    }

    protected function doRemoveItemFromCart(CartInterface $cart, ProductInterface $product)
    {
        $item = $this->findItemInCart($cart, $product);

        // add item to cart
        $rm = new \ReflectionMethod($cart, 'removeItem');
        $rm->setAccessible(true);
        $rm->invokeArgs($cart, array($item));
        $rm->setAccessible(false);
    }

    protected function initCartItem(ItemInterface $cartItem)
    {
        // todo: this should be moved into a handler
        //Default cart item description to the product name
        if ($product = $cartItem->getProduct()) {
            $cartItem->setName($product->getName());
            $cartItem->setDescription($cartItem->getName());
            $rpPricingSet = new \ReflectionProperty($cartItem, 'pricingSet');
            $rpPricingSet->setAccessible(true);
            $rpPricingSet->setValue($cartItem, $this->getPricingProvider()->createPricingSet());
            $rpPricingSet->setAccessible(false);
            // todo: especially this damn thing, and get the Interface out of the __construct
            if ($product instanceof $this->recurringInterface) {
                $rp = new \ReflectionProperty($cartItem, 'isRecurring');
                $rp->setAccessible(true);
                $rp->setValue($cartItem, true);
                $rp->setAccessible(false);
            }
        }
    }
}
