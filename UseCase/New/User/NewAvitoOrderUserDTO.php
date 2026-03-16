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

namespace BaksDev\Avito\Orders\UseCase\New\User;

use BaksDev\Avito\Orders\UseCase\New\User\UserAccount\NewAvitoUserAccountDTO;
use BaksDev\Avito\Orders\UseCase\New\User\UserProfile\NewAvitoUserProfileDTO;
use BaksDev\Orders\Order\Entity\User\OrderUserInterface;
use BaksDev\Users\Profile\UserProfile\Type\Event\UserProfileEventUid;
use BaksDev\Users\User\Type\Id\UserUid;
use BaksDev\Avito\Orders\UseCase\New\User\Delivery\NewAvitoOrderDeliveryDTO;
use BaksDev\Avito\Orders\UseCase\New\User\Payment\NewAvitoOrderPaymentDTO;
use Symfony\Component\Validator\Constraints as Assert;

final class NewAvitoOrderUserDTO implements OrderUserInterface
{
    /* Пользователь  */

    /** ID пользователя  */
    #[Assert\Uuid]
    private UserUid $usr;

    /** Новый Аккаунт */
    private NewAvitoUserAccountDTO $userAccount;

    /* Профиль пользователя */

    /** Идентификатор События!! профиля пользователя */
    #[Assert\Uuid]
    private ?UserProfileEventUid $profile = null;

    /** Новый профиль пользователя */
    private NewAvitoUserProfileDTO $userProfile;

    /** Способ оплаты */
    #[Assert\Valid]
    private NewAvitoOrderPaymentDTO $payment;

    /** Способ доставки */
    #[Assert\Valid]
    private NewAvitoOrderDeliveryDTO $delivery;


    public function __construct()
    {
        $this->userAccount = new NewAvitoUserAccountDTO();
        $this->userProfile = new NewAvitoUserProfileDTO();
        $this->payment = new NewAvitoOrderPaymentDTO();
        $this->delivery = new NewAvitoOrderDeliveryDTO();

        $this->usr = new UserUid();
    }


    /** ID пользователя */
    public function getUsr(): ?UserUid
    {
        return $this->usr;
    }


    public function setUsr(?UserUid $usr): self
    {

        $this->usr = $usr;
        return $this;
    }


    /** Идентификатор События!! профиля пользователя */

    public function getProfile(): ?UserProfileEventUid
    {

        return $this->profile;
    }


    public function setProfile(?UserProfileEventUid $profile): self
    {
        $this->profile = $profile;
        return $this;
    }


    /** Новый Аккаунт */

    public function getUserAccount(): ?NewAvitoUserAccountDTO
    {
        return $this->userAccount;
    }


    public function setUserAccount(?NewAvitoUserAccountDTO $userAccount): self
    {
        $this->userAccount = $userAccount;
        return $this;
    }


    /** Новый профиль пользователя */

    public function getUserProfile(): ?NewAvitoUserProfileDTO
    {
        return $this->userProfile;
    }


    public function setUserProfile(?NewAvitoUserProfileDTO $userProfile): self
    {

        $this->userProfile = $userProfile;
        return $this;
    }

    /** Способ оплаты */

    public function getPayment(): NewAvitoOrderPaymentDTO
    {
        return $this->payment;
    }


    public function setPayment(NewAvitoOrderPaymentDTO $payment): self
    {
        $this->payment = $payment;
        return $this;
    }


    /** Способ доставки */

    public function getDelivery(): NewAvitoOrderDeliveryDTO
    {
        return $this->delivery;
    }


    public function setDelivery(NewAvitoOrderDeliveryDTO $delivery): self
    {
        $this->delivery = $delivery;
        return $this;
    }
}
