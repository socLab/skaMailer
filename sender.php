<?php

/**
 * Created by PhpStorm.
 * User: Александр
 * Date: 25.02.2016
 * Time: 15:25
 */

class SenderException extends Exception { }

class Sender
{
    use Classinfo;

    private static $db = false;

    /**
     * Отправка почты
     *
     * @param array $addresses
     * @param $subject
     * @param $message
     * @param array $attachments
     * @return bool
     * @throws SenderException
     */
    private function mailSend (array $addresses, $subject, $message)
    {
        $mail = new PHPMailer;
        $mail->setLanguage('ru', '/srv/www/edu.soc-system.ru/classes/PHPMailer/language/phpmailer.lang-ru.php');
        $mail->CharSet = 'utf-8';
        $mail->isSMTP();
        $mail->Host = 'smtp.yandex.ru';
        $mail->SMTPAuth = true;
        $mail->Username = 'help@edu.soc-system.ru';
        $mail->Password = 'C6H5OH';
        $mail->SMTPSecure = 'tls';
        $mail->Port = 25;

        $mail->From = 'help@edu.soc-system.ru';
        $mail->FromName = 'edu.soc-system.ru';
        /*foreach ($addresses as $value)
        {
            $mail->addAddress($value);
        }*/
        $mail->parseAddresses($addresses);
        $mail->addReplyTo('help@edu.soc-system.ru', 'edu.soc-system.ru');

        $mail->WordWrap = 50;
        $mail->isHTML(true);

        $mail->Subject = $subject;
        $mail->Body    = $message;

        if(!$mail->send())
        {
            return false;
        }
        else
        {
            return true;
        }
    }

    private $getJobsToSend = "UPDATE
                                         delivery.jobs AS j
                                      SET
                                         is_performed = true
                                       LEFT JOIN
                                         delivery.templates AS t
                                         ON
                                           j.template_id = t.id
                                       LEFT JOIN
                                         delivery.\"job-user\" AS ju
                                         ON
                                           ju.job_id = j.id
                                       LEFT JOIN
                                         users AS u
                                         ON
                                           ju.user_id = u.id
                                       LEFT JOIN
                                         delivery.sended AS s
                                         ON
                                           s.job_id = j.id
                                             AND
                                           s.is_send
                                       WHERE
                                         NOT j.is_performed
                                           AND
                                         j.kind = 'Электронная почта'
                                           AND
                                         (j.type = 'Прямо сейчас'
                                            OR
                                            (j.type = 'Единоразово'
                                              AND
                                             date_part('epoch', CURRENT_TIMESTAMP) >= j.moment->time)
                                            OR
                                            (j.type = 'Ежедневно'
                                               AND
                                             to_char(CURRENT_TIMESTAMP, 'SSSS') >= j.moment->time)
                                               AND
                                             date_part('epoch', date_trunc('day', CURRENT_TIMESTAMP)) > s.datetime
                                            OR
                                            (j.type = 'Еженедельно'
                                               AND
                                             date_part('epoch', CURRENT_TIMESTAMP) = ANY(j.moment->day::int[])
                                               AND
                                             to_char(CURRENT_TIMESTAMP, 'SSSS') >= j.moment->time
                                               AND
                                             date_part('epoch', date_trunc('day', CURRENT_TIMESTAMP)) > s.datetime)
                                            OR
                                            (j.type = 'Ежемесячно'
                                               AND
                                             to_char(CURRENT_TIMESTAMP, 'DD') = j.moment->date
                                               AND
                                             to_char(CURRENT_TIMESTAMP, 'SSSS') >= j.moment->time
                                               AND
                                             date_part('epoch', date_trunc('day', CURRENT_TIMESTAMP)) > s.datetime)
                                            OR
                                            (j.type = 'Ежегодно'
                                               AND
                                             to_char(CURRENT_TIMESTAMP, 'DDD') = j.moment->date
                                               AND
                                             to_char(CURRENT_TIMESTAMP, 'SSSS') >= j.moment->time
                                               AND
                                             date_part('epoch', date_trunc('day', CURRENT_TIMESTAMP)) > s.datetime)
                                       RETURNING
                                         j.id,
                                         t.subject,
                                         t.text,
                                         string_agg(SELECT u.name || ' ' || u.surname || ' <' || u.email || '>' FROM (SELECT u.name, u.surname, u.email) AS u, ',') AS emails";

    private $finishSendind = "WITH i AS (UPDATE
                                delivery.jobs
                              SET
                                is_performed = false
                              WHERE
                                id = ANY (:job_ids::int[])
                              RETURNING
                                id,
                                name),
                              d AS (DELETE FROM
                                delivery.jobs
                              WHERE
                                id = ANY(:job_ids::int[])
                                  AND
                                (type = 'Прямо сейчас'
                                   OR
                                 type = 'Единоразово'))

                              INSERT INTO
                                delivery.sended
                                (job_id,
                                 is_send,
                                 user_array,
                                 job_name
                              SELECT
                                 i.id,
                                 :is_send,
                                 array_agg(ju.user_id),
                                 i.name
                              FROM
                                i
                              LEFT JOIN
                                delivery.\"job-user\" AS ju
                                ON
                                  ju.job_id = i.id
                              RETURNING
                                job_id";

    public function sendJobs ()
    {
        self::$db->beginTransaction();
        $result = $this->getJobsToSend();
        if (is_array($result))
        {
            foreach ($result AS $job)
            {
                if ($this->mailSend($job['emails'], $job['subject'], $job['text']))
                {
                    $ok_ids[] = $job['id'];
                }
                else
                {
                    $fail_ids[] = $job['id'];
                }
            }
            if ($ok_ids)
            {
                if (!is_array($this->finishSendind(['job_ids' => '{' . implode(',', $ok_ids) . '}', 'is_send' => true])))
                {
                    throw new SenderException('');
                }
            }
            else
            {
                throw new SenderException('Ни одна задача не была выполнена');
            }
            if ($fail_ids)
            {
                if (!is_array($this->finishSendind(['job_ids' => '{' . implode(',', $fail_ids) . '}', 'is_send' => false])))
                {
                    throw new SenderException('');
                }
            }
        }
        return true;
    }

    private $getJobList = "SELECT
                             id,
                             name,
                             type,
                             kind
                          FROM
                            delivery.jobs";

    private $getJob = "SELECT
                         j.id,
                         j.name,
                         j.type,
                         j.moment,
                         j.kind,
                         j.description,
                         t.id,
                         t.name,
                         json_agg(u ORDER BY u.name) AS userlist
                       FROM
                         delivery.jobs AS j
                       LEFT JOIN
                         delivery.templates AS t
                         ON
                           j.template_id = t.id
                       LEFT JOIN (SELECT
                             u.id,
                             get_full_name(u.surname, u.name, u.fathername) AS name,
                            (CASE
                               WHEN
                                (u.actual_city IS NOT NULL
                                   OR
                                 u.actual_city != '')
                               THEN
                                 u.actual_city
                               ELSE
                                 u.registration_city
                             END) AS city,
                             u.email,
                             u.education,
                             u.phone_number,
                             u.path,
                            (CASE
                               WHEN
                                 ju.user_id IS NOT NULL
                               THEN
                                 'checked'
                               ELSE
                                 ''
                              END) AS status,
                             ju.job_id
                            FROM
                              users AS u
                            RIGHT JOIN
                              delivery.\"job-user\" AS ju
                              ON
                                ju.user_id = u.id
                            WHERE
                              u.is_active) AS u
                         ON
                           j.id = u.job_id
                         WHERE
                           j.id = :id
                         GROUP BY
                           j.id,
                           t.id";

    private $newTemplate = "INSERT INTO
                              delivery.templates
                              ([fields])
                            VALUES
                              [expression]
                            RETURNING
                              id,
                              [fields]";

    private $updateTemplate = "UPDATE
                                delivery.templates
                              SET
                                [expression]
                              WHERE
                                id = :id
                              RETURNING
                                id,
                                [fields]";

    private $newJob = "WITH ti AS (INSERT INTO
                         delivery.templates
                         ([fields:not(user_ids)])
                       VALUES
                         [expression:not(user_ids)]
                       RETURNING
                         id,
                         [fields:not(user_ids)]),

                       jui AS (INSERT INTO
                         delivery.\"job-user\"
                       SELECT
                         u.id,
                         ti.id
                       FROM
                         users AS u, ti
                       WHERE
                         u.id = ANY({:user_ids}::int[]))

                       SELECT
                         *
                       FROM
                         ti";

    private $clearJobsUsers = "DELETE FROM
                                delivery.\"job-user\"
                              WHERE
                                job_id = :id
                              RETURNING
                                job_id";

    private $updateJobSQL = "jui AS (INSERT INTO
                             delivery.\"job-user\"
                           SELECT
                             u.id,
                             :id
                           FROM
                             users AS u, ti
                           WHERE
                             u.id = ANY({:user_ids}::int[]))

                           UPDATE
                             delivery.jobs
                           SET
                             [expression:not(user_ids, id)]
                           WHERE
                             id = :id
                           RETURNING
                             *";

    public function updateJob (array $data)
    {
        self::$db->beginTransaction();

        $this->clearJobsUsers(['id' => $data['id']]);

        $result = $this->updateJobSQL(Registry::getAllValidateFields($data, 'Sender::updateJobSQL'));
        if (is_array($result))
        {
            return $result;
        }
        else
        {
            throw new SenderException('Не удалось сохранить изменения задаче');
        }
    }

    private $deleteTemplate = "DELETE FROM
                                 delivery.templates
                               WHERE
                                 id = :id
                               RETURNING
                                 id";

    private $deleteJob = "DELETE FROM
                            delivery.jobs
                          WHERE
                            id = :id
                          RETURNING
                            id";

}