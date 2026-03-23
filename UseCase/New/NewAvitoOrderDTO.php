<?php
/*
 * Copyright 2026.  Baks.dev <admin@baks.dev>
 *
 *  Permission is hereby granted, free of charge, to any person obtaining a copy
 *  of this software and associated documentation files (the "Software"), to deal
 *  in the Software without restriction, including without limitation the rights
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *  copies of the Software, and to permit persons to whom the Software is furnished
 *  to do so, subject to the following conditions:
 *
 *  The above copyright notice and this permission notice shall be included in all
 *  copies or substantial portions of the Software.
 *
 *  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 *  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 *  FITNESS FOR A PARTICULAR PURPOSE AND NON INFRINGEMENT. IN NO EVENT SHALL THE
 *  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 *  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 *  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 *  THE SOFTWARE.
 */

declare(strict_types=1);

namespace BaksDev\Avito\Orders\UseCase\New;

use BaksDev\Avito\Orders\UseCase\New\Invariable\NewAvitoOrderInvariableDTO;
use BaksDev\Avito\Orders\UseCase\New\Posting\NewAvitoOrderPostingDTO;
use BaksDev\Avito\Orders\UseCase\New\Products\NewAvitoOrderProductDTO;
use BaksDev\Avito\Orders\UseCase\New\User\NewAvitoOrderUserDTO;
use BaksDev\Orders\Order\Entity\Event\OrderEventInterface;
use BaksDev\Orders\Order\Type\Event\OrderEventUid;
use BaksDev\Orders\Order\Type\Status\OrderStatus;
use BaksDev\Orders\Order\Type\Status\OrderStatus\Collection\OrderStatusNew;
use BaksDev\Orders\Order\Type\Status\OrderStatus\OrderStatusInterface;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Validator\Constraints as Assert;

/** @see OrderEvent */
final class NewAvitoOrderDTO implements OrderEventInterface
{
    /** Идентификатор события */
    #[Assert\Uuid]
    private ?OrderEventUid $id = null;

    /** Постоянная величина */
    #[Assert\Valid]
    private NewAvitoOrderInvariableDTO $invariable;

    /** Идентификатор отпарвления */
    #[Assert\Valid]
    private NewAvitoOrderPostingDTO $posting;

    /** Дата заказа */
    #[Assert\NotBlank]
    private DateTimeImmutable $created;

    /** Статус заказа */
    private OrderStatus $status;

    /** Коллекция продукции в заказе */
    #[Assert\Valid]
    private ArrayCollection $product;

    /** Пользователь */
    #[Assert\Valid]
    private NewAvitoOrderUserDTO $usr;

    /** Комментарий к заказу */
    private ?string $comment = null;

    /** Информация о покупателе */
    private ?array $buyer;

    public function __construct()
    {
        $this->invariable = new NewAvitoOrderInvariableDTO();
        $this->product = new ArrayCollection();
        $this->usr = new NewAvitoOrderUserDTO();
        $this->status = new OrderStatus(OrderStatusNew::STATUS);
        $this->posting = new NewAvitoOrderPostingDTO();
    }


    /** @see OrderEvent */
    public function getEvent(): ?OrderEventUid
    {
        return $this->id;
    }

    public function setId(?OrderEventUid $id): self
    {
        $this->id = $id;
        return $this;
    }

    /**
     * Status
     */
    public function getStatus(): OrderStatus
    {
        return $this->status;
    }

    public function getStatusEquals(mixed $status): bool
    {
        return $this->status->equals($status);
    }

    public function setStatus(OrderStatus|OrderStatusInterface|string $status): self
    {
        $this->status = new OrderStatus($status);
        return $this;
    }


    /**
     * Number
     */
    public function getPostingNumber(): string
    {
        return $this->posting->getValue();
    }


    /**
     * Коллекция продукции в заказе
     *
     * @return ArrayCollection<NewAvitoOrderProductDTO>
     */
    public function getProduct(): ArrayCollection
    {
        return $this->product;
    }

    public function setProduct(ArrayCollection $product): self
    {
        $this->product = $product;
        return $this;
    }

    public function addProduct(NewAvitoOrderProductDTO $product): self
    {
        $filter = $this->product->filter(function(NewAvitoOrderProductDTO $element) use ($product) {
            return $element->getArticle() === $product->getArticle();
        });

        if($filter->isEmpty())
        {
            $this->product->add($product);
        }

        return $this;
    }

    public function removeProduct(NewAvitoOrderProductDTO $product): self
    {
        $this->product->removeElement($product);
        return $this;
    }

    /**
     * Usr
     */
    public function getUsr(): NewAvitoOrderUserDTO
    {
        return $this->usr;
    }

    /**
     * Comment
     */
    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): self
    {
        $this->comment = $comment;
        return $this;
    }

    /**
     * Buyer
     */
    public function getBuyer(): ?array
    {
        return $this->buyer;
    }

    /**
     * Invariable
     */
    public function getInvariable(): NewAvitoOrderInvariableDTO
    {
        return $this->invariable;
    }

    public function getPosting(): NewAvitoOrderPostingDTO
    {
        return $this->posting;
    }

    public function getOrderNumber(): ?string
    {
        return $this->invariable->getNumber();
    }

    public function setCreated(DateTimeImmutable $created): self
    {
        $this->created = $created;
        return $this;
    }

    public function setBuyer(?array $buyer): self
    {
        $this->buyer = $buyer;
        return $this;
    }
}