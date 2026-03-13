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

namespace BaksDev\Avito\Orders\Api\Get\OrdersInfo;

use BaksDev\Avito\Orders\Api\Get\OrdersInfo\Product\AvitoGetOrdersInfoProductDTO;
use BaksDev\Reference\Money\Type\Money;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;

final class AvitoGetOrdersInfoDTO
{
    private string $id;

    private DateTimeImmutable $creationDate;

    private ArrayCollection $products;

    private ?string $address = null;

    private ?string $posting = null;

    private ?string $buyerName = null;

    private ?string $buyerNumber = null;

    private DateTimeImmutable $deliveryDate;

    private ?string $pvzAddress = null;


    public function __construct(array $data)
    {
        $this->id = $data['id'];
        $this->creationDate = new DateTimeImmutable($data['createdAt']);


        /**
         * Products
         */
        $this->products = new ArrayCollection();

        foreach($data['items'] as $item)
        {
            /**
             * Проверяем, является ли продукт комплектом из нескольких - в таком случае цену одного товара необходимо
             * разделить на количество в комплекте, а количество умножить на это число
             */
            $kit = 1;
            if (preg_match('/KIT(\d+)$/', $item['id'], $matches)) {
                $kit = $matches[1];
            }

            $this->products->add(new AvitoGetOrdersInfoProductDTO(
                preg_replace('/-KIT\d+$/', '', $item['id']),
                new Money($item['prices']['total'] / $kit),
                $item['count'] * $kit
            ));
        }


        /**
         * Address
         */
        if(isset($data['delivery']['courierInfo'])) {
            $this->address = $data['delivery']['courierInfo'];
        }

        if(isset($data['delivery']['terminalInfo'])) {
            $this->address = $data['delivery']['terminalInfo'];
        }


        /** Постинг - это номер отслеживания, кроме случаев, когда его нет - тогда это идентификатор заказа */
        $this->posting = isset($data['delivery']['trackingNumber']) ? $data['delivery']['trackingNumber'] : $data['id'];


        /**
         * Покупатель
         */
        $this->buyerName = isset($data['delivery']['buyerInfo']) ? $data['delivery']['buyerInfo']['fullName'] : null;
        $this->buyerNumber = isset($data['delivery']['buyerInfo']) ? $data['delivery']['buyerInfo']['phoneNumber'] : null;


        /**
         * Дата доставки
         */
        $this->deliveryDate = new DateTimeImmutable($data['schedules']['deliveryDateMax']);


        /**
         * Адрес ПВЗ
         */
        $this->pvzAddress = isset($data['delivery']['terminalInfo']) ? $data['delivery']['terminalInfo']['address'] : null;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCreationDate(): DateTimeImmutable
    {
        return new $this->creationDate;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    /** @return ArrayCollection<AvitoGetOrdersInfoProductDTO> */
    public function getProducts(): ArrayCollection
    {
        return $this->products;
    }

    public function getPosting(): ?string
    {
        return $this->posting;
    }

    public function getDeliveryDate(): DateTimeImmutable
    {
        return $this->deliveryDate;
    }

    public function getBuyerName(): ?string
    {
        return $this->buyerName;
    }

    public function getBuyerNumber(): ?string
    {
        return $this->buyerNumber;
    }

    public function getPvzAddress(): ?string
    {
        return $this->pvzAddress;
    }
}