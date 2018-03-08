<?php
/**
 * This file is part of the Passwords App
 * created by Marius David Wieschollek
 * and licensed under the AGPL.
 */

namespace OCA\Passwords\Cron;

use OC\BackgroundJob\TimedJob;
use OCA\Passwords\AppInfo\Application;
use OCA\Passwords\Db\PasswordRevision;
use OCA\Passwords\Db\PasswordRevisionMapper;
use OCA\Passwords\Helper\SecurityCheck\AbstractSecurityCheckHelper;
use OCA\Passwords\Notification\Notifier;
use OCA\Passwords\Services\HelperService;
use OCA\Passwords\Services\LoggingService;
use OCA\Passwords\Services\MailService;
use OCP\Notification\IManager;

/**
 * Class CheckPasswordsJob
 *
 * @package OCA\Passwords\Cron
 */
class CheckPasswordsJob extends TimedJob {

    /**
     * @var LoggingService
     */
    protected $logger;

    /**
     * @var MailService
     */
    protected $mailService;

    /**
     * @var HelperService
     */
    protected $helperService;

    /**
     * @var PasswordRevisionMapper
     */
    protected $revisionMapper;

    /**
     * @var IManager
     */
    protected $notificationManager;

    /**
     * @var array
     */
    protected $badPasswords = [];

    /**
     * CheckPasswordsJob constructor.
     *
     * @param LoggingService         $logger
     * @param MailService            $mailService
     * @param HelperService          $helperService
     * @param IManager               $notificationManager
     * @param PasswordRevisionMapper $revisionMapper
     */
    public function __construct(
        LoggingService $logger,
        MailService $mailService,
        HelperService $helperService,
        IManager $notificationManager,
        PasswordRevisionMapper $revisionMapper
    ) {
        // Run once per day
        //$this->setInterval(24 * 60 * 60);
        $this->setInterval(1);
        $this->logger              = $logger;
        $this->helperService       = $helperService;
        $this->revisionMapper      = $revisionMapper;
        $this->notificationManager = $notificationManager;
        $this->mailService         = $mailService;
    }

    /**
     * @param $argument
     *
     * @throws \Exception
     */
    protected function run($argument): void {
        $securityHelper = $this->helperService->getSecurityHelper();

        if($securityHelper->dbUpdateRequired()) {
            $securityHelper->updateDb();
        }
        $this->checkRevisionStatus($securityHelper);
    }

    /**
     * @param $securityHelper
     *
     * @throws \Exception
     */
    protected function checkRevisionStatus(AbstractSecurityCheckHelper $securityHelper): void {
        /** @var PasswordRevision[] $revisions */
        $revisions = $this->revisionMapper->findAllMatching(['status', 2, '!=']);

        $badRevisionCounter = 0;
        foreach($revisions as $revision) {
            $oldStatus = $revision->getStatus();
            $newStatus = $securityHelper->getRevisionSecurityLevel($revision);

            if($oldStatus != $newStatus) {
                $revision->setStatus($newStatus);
                $this->revisionMapper->update($revision);
                $this->sendBadPasswordNotification($revision);
                $badRevisionCounter++;
            }
        }

        $this->sendNotifications();
        $this->sendEmails();
        $this->logger->info(['Checked %s passwords. %s new bad revisions found', count($revisions), $badRevisionCounter]);
    }

    /**
     * @param PasswordRevision $revision
     */
    protected function sendBadPasswordNotification(PasswordRevision $revision): void {
        try {
            $current = $this->revisionMapper->findCurrentRevisionByModel($revision->getModel());
            if($current->getUuid() === $revision->getUuid()) {
                $user = $revision->getUserId();
                if(!isset($this->badPasswords[ $user ])) {
                    $this->badPasswords[ $user ] = 1;
                } else {
                    $this->badPasswords[ $user ]++;
                }
            }
        } catch(\Throwable $e) {
            $this->logger->logException($e);
        }
    }

    /**
     *
     */
    protected function sendNotifications(): void {
        foreach($this->badPasswords as $user => $count) {
            $notification = $this->notificationManager->createNotification();

            $notification->setApp(Application::APP_NAME)
                         ->setUser($user)
                         ->setObject('object', 'password')
                         ->setSubject(Notifier::NOTIFICATION_PASSWORD_BAD, ['count' => $count])
                         ->setDateTime(new \DateTime());
            $this->notificationManager->notify($notification);
        }
    }

    /**
     *
     */
    protected function sendEmails(): void {
        foreach($this->badPasswords as $user => $count) {
            $this->mailService->sendBadPasswordMail($user, $count);
        }
    }
}