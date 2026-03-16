<?php
/*
 *  Copyright 2026.  Baks.dev <admin@baks.dev>
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

namespace BaksDev\Avito\Orders\Messenger\Schedules\NewOrders;

use BaksDev\Avito\Orders\Api\Get\OrdersInfo\AvitoGetOrdersInfoDTO;
use BaksDev\Avito\Orders\Api\Get\OrdersInfo\AvitoGetOrdersInfoRequest;
use BaksDev\Avito\Orders\Api\Get\OrdersInfo\Product\AvitoGetOrdersInfoProductDTO;
use BaksDev\Avito\Orders\Type\DeliveryType\TypeDeliveryDbsAvito;
use BaksDev\Avito\Orders\Type\DeliveryType\TypeDeliveryFbsAvito;
use BaksDev\Avito\Orders\Type\PaymentType\TypePaymentDbsAvito;
use BaksDev\Avito\Orders\Type\PaymentType\TypePaymentFbsAvito;
use BaksDev\Avito\Orders\Type\ProfileType\TypeProfileFbsAvito;
use BaksDev\Avito\Orders\UseCase\New\NewAvitoOrderDTO;
use BaksDev\Avito\Orders\UseCase\New\NewAvitoOrderHandler;
use BaksDev\Avito\Orders\UseCase\New\Products\Items\NewAvitoOrderProductItemDTO;
use BaksDev\Avito\Orders\UseCase\New\Products\NewAvitoOrderProductDTO;
use BaksDev\Avito\Orders\UseCase\New\User\Delivery\Field\NewAvitoOrderDeliveryFieldDTO;
use BaksDev\Avito\Orders\UseCase\New\User\UserProfile\Value\NewAvitoUserProfileValueDTO;
use BaksDev\Avito\Repository\AllTokensByProfile\AvitoTokensByProfileInterface;
use BaksDev\Avito\Type\Id\AvitoTokenUid;
use BaksDev\Core\Deduplicator\DeduplicatorInterface;
use BaksDev\Core\Type\Field\InputField;
use BaksDev\Delivery\Repository\CurrentDeliveryEvent\CurrentDeliveryEventInterface;
use BaksDev\Delivery\Type\Event\DeliveryEventUid;
use BaksDev\Delivery\Type\Id\DeliveryUid;
use BaksDev\Field\Pack\Phone\Type\PhoneField;
use BaksDev\Orders\Order\Entity\Order;
use BaksDev\Orders\Order\Repository\FieldByDeliveryChoice\FieldByDeliveryChoiceInterface;
use BaksDev\Payment\Type\Id\PaymentUid;
use BaksDev\Products\Product\Repository\CurrentProductByArticle\CurrentProductByBarcodeResult;
use BaksDev\Products\Product\Repository\CurrentProductByArticle\ProductConstByArticleInterface;
use BaksDev\Reference\Currency\Type\Currencies\RUR;
use BaksDev\Reference\Currency\Type\Currency;
use BaksDev\Reference\Money\Type\Money;
use BaksDev\Users\Address\Api\YandexMarketAddressRequest;
use BaksDev\Users\Profile\TypeProfile\Type\Id\TypeProfileUid;
use BaksDev\Users\Profile\UserProfile\Repository\FieldValueForm\FieldValueFormDTO;
use BaksDev\Users\Profile\UserProfile\Repository\FieldValueForm\FieldValueFormInterface;
use BaksDev\Users\Profile\UserProfile\Repository\UserByUserProfile\UserByUserProfileInterface;
use BaksDev\Users\Profile\UserProfile\Repository\UserProfileById\UserProfileByIdInterface;
use BaksDev\Users\Profile\UserProfile\Repository\UserProfileById\UserProfileResult;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use BaksDev\Avito\Orders\Schedule\NewOrders\NewOrdersSchedule;
use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Generator;

#[AsMessageHandler(priority: 0)]
final readonly class NewAvitoOrdersScheduleHandler
{
    private UserProfileUid $profile;

    public function __construct(
        #[Target('avitoOrdersLogger')] private LoggerInterface $Logger,
        private AvitoGetOrdersInfoRequest $AvitoGetOrdersInfoRequest,
        private NewAvitoOrderHandler $AvitoOrderHandler,
        private AvitoTokensByProfileInterface $AvitoTokensByProfile,
        private DeduplicatorInterface $Deduplicator,
        private YandexMarketAddressRequest $avitoAddressRequest,
        private UserProfileByIdInterface $UserProfileByIdRepository,
        private UserByUserProfileInterface $UserByUserProfileRepository,
        private ProductConstByArticleInterface $ProductConstByArticleRepository,
        private FieldValueFormInterface $FieldValueRepository,
        private FieldByDeliveryChoiceInterface $deliveryFields,
        private CurrentDeliveryEventInterface $currentDeliveryEvent,
    )
    {}

    public function __invoke(NewAvitoOrdersScheduleMessage $message): void
    {
        $this->profile = $message->getProfile();


        /** Получаем все токены профиля */

        $tokensByProfile = $this->AvitoTokensByProfile
            ->forProfile($message->getProfile())
            ->findAll();

        if(false === $tokensByProfile || false === $tokensByProfile->valid())
        {
            return;
        }


        foreach($tokensByProfile as $avitoTokenUid)
        {
            /**
             * Ограничиваем периодичность запросов
             */

            $Deduplicator = $this->Deduplicator
                ->namespace('avito-orders')
                ->expiresAfter(NewOrdersSchedule::INTERVAL)
                ->deduplication([self::class, (string) $avitoTokenUid]);

            if($Deduplicator->isExecuted())
            {
                continue;
            }

            /** Добавляем дедубликатор обновления (удалям в конце данного процесса) */
            $Deduplicator->save();

            /**
             * Получаем список НОВЫХ сборочных заданий по основному идентификатору компании
             */

            $orders = $this->AvitoGetOrdersInfoRequest
                ->forTokenIdentifier($avitoTokenUid)
                ->interval(NewOrdersSchedule::INTERVAL)
                ->findAll();

            if(false === $orders || false === $orders->valid())
            {
                $Deduplicator->delete();
                continue;
            }

            $this->ordersCreate($orders, $avitoTokenUid);

            /** Удаляем дедубликатор обновления */
            $Deduplicator->delete();
        }
    }

    /** @param Generator<NewAvitoOrderDTO> $orders */
    private function ordersCreate(Generator $orders, AvitoTokenUid $token): void
    {
        /** @var AvitoGetOrdersInfoDTO $avitoGetOrdersInfoDTO */
        foreach($orders as $avitoGetOrdersInfoDTO)
        {
            /** Индекс дедубдикации по номеру заказа */
            $Deduplicator = $this->Deduplicator
                ->namespace('avito-orders')
                ->deduplication([
                    $avitoGetOrdersInfoDTO->getPosting(),
                    self::class,
                ]);

            if($Deduplicator->isExecuted())
            {
                continue;
            }


            /** Создаем и заполняем DTO нового заказа */
            $avitoOrderDTO = new NewAvitoOrderDTO();


            /**
             * Invariable
             */

            $user = $this->UserByUserProfileRepository
                ->forProfile($this->profile)
                ->find();

            if($user === false)
            {
                continue;
                // return 'Пользователь по профилю не найден';
            }

            $avitoOrderDTO
                ->getInvariable()
                ->setCreated($avitoGetOrdersInfoDTO->getCreationDate() ?: new DateTimeImmutable('now'))
                ->setProfile($this->profile)
                ->setToken($token)
                ->setNumber('A-'.$avitoGetOrdersInfoDTO->getId()) // помечаем заказ префиксом Y
                ->setUsr($user->getId());


            /** Posting */
            $avitoOrderDTO
                ->getPosting()
                ->setValue('Y-'.$avitoGetOrdersInfoDTO->getPosting());


            /**
             * Created
             */
            $avitoOrderDTO->setCreated($avitoGetOrdersInfoDTO->getCreationDate() ?: new DateTimeImmutable('now'));


            /**
             * Продукция
             * @var AvitoGetOrdersInfoProductDTO $product
             */
            foreach($avitoGetOrdersInfoDTO->getProducts() as $product)
            {
                $newOrderProductDTO = new NewAvitoOrderProductDTO()->setArticle($product->getArticle());

                $newOrderPriceDTO = $newOrderProductDTO->getPrice();

                $productData = $this->ProductConstByArticleRepository->find($product->getArticle());

                if(false === ($productData instanceof CurrentProductByBarcodeResult))
                {
                    /** Если какой-то продукт для данного заказа не был найден - пропускаем такой заказ */
                    $this->Logger->warning(
                        sprintf('Артикул товара %s не найден', $product->getArticle()),
                        [self::class.':'.__LINE__]
                    );
                    continue 2;
                }

                $newOrderPriceDTO
                    ->setPrice(new Money($product->getTotalPrice())) // Стоимость товара в валюте магазина до применения скидок.
                    ->setCurrency(new Currency(RUR::CURRENCY))
                    ->setTotal($product->getCount());

                $avitoOrderDTO->addProduct($newOrderProductDTO
                    ->setProduct($productData->getEvent())
                    ->setOffer($productData->getOffer())
                    ->setVariation($productData->getVariation())
                    ->setModification($productData->getModification())
                );


                /**
                 * Items
                 * Создаем единицу продукта по количеству продукта в заказе
                 */
                for($i = 0; $i < $product->getCount(); $i++)
                {
                    $item = new NewAvitoOrderProductItemDTO();

                    /**
                     * Присваиваем цену из продукта в заказе
                     */
                    $item->getPrice()
                        ->setPrice($product->getTotalPrice())
                        ->setCurrency(new Currency(RUR::CURRENCY));

                    $newOrderProductDTO->addItem($item);
                }
            }


            /**
             * User
             * Если удалось получить адрес по API - значит заказ DSB, в противном случае - FBS
             */

            /** Способ оплаты */
            $payment = new PaymentUid(false === empty($avitoGetOrdersInfoDTO->getAddress()) ? TypePaymentDbsAvito::class : TypePaymentFbsAvito::class);
            $avitoOrderDTO
                ->getUsr()
                ->getPayment()
                ->setPayment($payment);

            /** Способ доставки Avito */
            $delivery = new DeliveryUid(false === empty($avitoGetOrdersInfoDTO->getAddress()) ? TypeDeliveryDbsAvito::class : TypeDeliveryFbsAvito::class);

            /**
             * API возвращает данные по адресам только для DBS. Для FBS можно воспользоваться адресом профиля в системе
             */
            $address = $avitoGetOrdersInfoDTO->getAddress();
            if(true === empty($address))
            {
                $userProfileByIdResult = $this->UserProfileByIdRepository
                    ->profile($this->profile)
                    ->find();

                if(false === ($userProfileByIdResult instanceof UserProfileResult))
                {
                    continue;
                }

                $address = $userProfileByIdResult->getLocation();
            }

            /**
             * Получим данные для заполнения координат по адресу - если адрес был возвращен по API - находим по нему
             * координаты; в ином случае используем данные из профиля
             */
            if(false === empty($avitoGetOrdersInfoDTO->getAddress()))
            {
                $avitoAddressResult = $this->avitoAddressRequest->getAddress($address);

                if(true === empty($avitoAddressResult))
                {
                    continue;
                }

                $latitude = $avitoAddressResult->getLatitude();
                $longitude = $avitoAddressResult->getLongitude();
            }
            else
            {
                $latitude = $userProfileByIdResult->getLatitude();
                $longitude = $userProfileByIdResult->getLongitude();
            }

            $avitoOrderDTO
                ->getUsr()
                ->getDelivery()
                ->setDelivery($delivery)
                ->setAddress($address)
                ->setDeliveryDate($avitoGetOrdersInfoDTO->getDeliveryDate())
                ->setLatitude($latitude)
                ->setLongitude($longitude);


            $OrderProfileDTO = $avitoOrderDTO->getUsr()->getUserProfile();
            $OrderPaymentDTO = $avitoOrderDTO->getUsr()->getPayment();
            $OrderDeliveryDTO = $avitoOrderDTO->getUsr()->getDelivery();

            if(false === empty($avitoGetOrdersInfoDTO->getAddress()))
            {
                /** Тип профиля FBS Avito Market */
                $Profile = new TypeProfileUid(TypeProfileFbsAvito::class);

                $OrderProfileDTO?->setType($Profile);

                /** Способ доставки Avito Market (FBS Avito Market) */
                $Delivery = new DeliveryUid(TypeDeliveryFbsAvito::class);
                $OrderDeliveryDTO->setDelivery($Delivery);

                /** Способ оплаты FBS Avito Market */
                $Payment = new PaymentUid(TypePaymentFbsAvito::class);
                $OrderPaymentDTO->setPayment($Payment);
            }


            if(true === empty($avitoGetOrdersInfoDTO->getAddress()))
            {
                /** Тип профиля FBS Avito */
                $Profile = new TypeProfileUid(TypeProfileFbsAvito::class);

                $OrderProfileDTO?->setType($Profile);

                /** Способ доставки Avito (FBS Avito) */
                $Delivery = new DeliveryUid(TypeDeliveryFbsAvito::class);
                $OrderDeliveryDTO->setDelivery($Delivery);

                /** Способ оплаты FBS Avito */
                $Payment = new PaymentUid(TypePaymentFbsAvito::class);
                $OrderPaymentDTO->setPayment($Payment);
            }


            /** Присваиваем информацию о покупателе */
            $buyer = [];

            if(false === empty($avitoGetOrdersInfoDTO->getBuyerName()))
            {
                $buyer['name'] = $avitoGetOrdersInfoDTO->getBuyerName();
            }

            if(false === empty($avitoGetOrdersInfoDTO->getBuyerNumber()))
            {
                $buyer['number'] = $avitoGetOrdersInfoDTO->getBuyerNumber();
            }

            $avitoOrderDTO->setBuyer($buyer);

            $this->fillProfile($avitoOrderDTO);
            $this->fillDelivery($avitoOrderDTO, $avitoGetOrdersInfoDTO);


            $handle = $this->AvitoOrderHandler->handle($avitoOrderDTO);

            if($handle instanceof Order)
            {
                $this->Logger->info(
                    sprintf('Добавили новый заказ %s', $avitoOrderDTO->getPostingNumber()),
                    [self::class.':'.__LINE__],
                );

                $Deduplicator->save();
            }
        }
    }

    public function fillProfile(NewAvitoOrderDTO $command): void
    {
        if(empty($command->getBuyer()))
        {
            return;
        }

        /** Профиль пользователя  */
        $userProfileDTO = $command->getUsr()->getUserProfile();

        if(null === $userProfileDTO)
        {
            return;
        }

        /** Идентификатор типа профиля  */
        $typeProfileUid = $userProfileDTO->getType();

        if(null === $typeProfileUid)
        {
            return;
        }


        /** Определяем свойства клиента при доставке DBS */
        $profileFields = $this->FieldValueRepository->get($typeProfileUid);

        /** @var FieldValueFormDTO $profileField */
        foreach($profileFields as $profileField)
        {
            if($profileField->getType()->getType() === 'contact_field')
            {
                $userProfileValueDTO = new NewAvitoUserProfileValueDTO();
                $userProfileValueDTO->setField($profileField->getField());
                $userProfileValueDTO->setValue($command->getBuyer()['name']);
                $userProfileDTO->addValue($userProfileValueDTO);

                continue;
            }

            if(isset($command->getBuyer()['phone']) && $profileField->getType()->getType() === 'phone_field')
            {
                $phone = PhoneField::formater($command->getBuyer()['phone']);

                $userProfileValueDTO = new NewAvitoUserProfileValueDTO();
                $userProfileValueDTO->setField($profileField->getField());
                $userProfileValueDTO->setValue($phone);
                $userProfileDTO->addValue($userProfileValueDTO);
            }

        }
    }

    public function fillDelivery(NewAvitoOrderDTO $command, AvitoGetOrdersInfoDTO $avitoGetOrdersInfoDTO): void
    {
        $OrderDeliveryDTO = $command->getUsr()->getDelivery();


        /**
         * Определяем свойства доставки и присваиваем адрес
         */

        $fields = $this->deliveryFields->fetchDeliveryFields($OrderDeliveryDTO->getDelivery());


        /** Указываем адрес доставки */

        if($fields)
        {
            $address_field = array_filter($fields, function($v) {
                /** @var InputField $InputField */
                return $v->getType()->getType() === 'address_field';
            });

            $address_field = current($address_field);

            if($address_field)
            {
                $OrderDeliveryFieldDTO = new NewAvitoOrderDeliveryFieldDTO();
                $OrderDeliveryFieldDTO->setField($address_field);
                $OrderDeliveryFieldDTO->setValue($OrderDeliveryDTO->getAddress());
                $OrderDeliveryDTO->addField($OrderDeliveryFieldDTO);
            }


            /** При самовывозе указываем ПВЗ */
            if(false === empty($avitoGetOrdersInfoDTO->getPvzAddress()))
            {
                $contacts_region = array_filter($fields, function($v) {
                    /** @var InputField $InputField */
                    return $v->getType()->getType() === 'contacts_region_type';
                });

                $contacts_field = current($contacts_region);

                if($contacts_field)
                {
                    $OrderDeliveryFieldDTO = new NewAvitoOrderDeliveryFieldDTO();
                    $OrderDeliveryFieldDTO->setField($contacts_field);


                    $OrderDeliveryFieldDTO->setValue((string) $avitoGetOrdersInfoDTO->getPvzAddress());

                    $OrderDeliveryDTO->addField($OrderDeliveryFieldDTO);
                }
            }
        }


        /**
         * Присваиваем активное событие доставки
         */

        $DeliveryEventUid = $this->currentDeliveryEvent
            ->forDelivery($OrderDeliveryDTO->getDelivery())
            ->getId();

        if(false === $DeliveryEventUid instanceof DeliveryEventUid)
        {

            throw new InvalidArgumentException(
                sprintf('Способ доставки не найден! Выполните комманду Upgrade типа %s : ', $OrderDeliveryDTO->getDelivery()),
            );
        }

        $OrderDeliveryDTO->setEvent($DeliveryEventUid);

    }
}
