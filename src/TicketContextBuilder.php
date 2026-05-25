<?php

class TicketContextBuilder {
    private $maxThreadEntries;

    public function __construct(int $maxThreadEntries = 20) {
        $this->maxThreadEntries = $maxThreadEntries;
    }

    /**
     * Extract structured context from a ticket for AI consumption.
     *
     * @param Ticket $ticket
     * @return array
     */
    public function build($ticket): array {
        $helpTopic = '';
        if (method_exists($ticket, 'getTopic') && $ticket->getTopic()) {
            $topic = $ticket->getTopic();
            $helpTopic = method_exists($topic, 'getName') ? $topic->getName() : (string) $topic;
        }

        $data = array(
            'id' => $ticket->getId(),
            'number' => $ticket->getNumber(),
            'subject' => $ticket->getSubject(),
            'dept_id' => $ticket->getDeptId(),
            'department' => $ticket->getDept() ? $ticket->getDept()->getName() : '',
            'priority' => $ticket->getPriority(),
            'help_topic' => $helpTopic,
            'custom_fields' => $this->extractCustomFields($ticket),
            'content' => '',
            'thread' => array(),
        );

        $thread = $ticket->getThread();
        if (!$thread) {
            return $data;
        }

        $entries = $thread->getEntries();
        $count = 0;

        foreach ($entries as $entry) {
            if ($count >= $this->maxThreadEntries) {
                break;
            }

            $body = $entry->getBody();
            $cleanBody = '';
            if ($body) {
                $cleanBody = strip_tags(
                    is_object($body) && method_exists($body, 'getClean')
                        ? $body->getClean()
                        : (string) $body
                );
                $cleanBody = preg_replace('/\s+/', ' ', trim($cleanBody));
            }

            $poster = $entry->getPoster();
            $posterName = is_object($poster) && method_exists($poster, 'getName')
                ? (string) $poster->getName()
                : (string) $poster;

            $type = $entry->getType();
            $role = ($type === 'M') ? 'customer' : 'agent';

            if ($count === 0) {
                $data['content'] = $cleanBody;
            }

            $data['thread'][] = array(
                'role' => $role,
                'poster' => $posterName,
                'body' => $cleanBody,
                'type' => $type,
            );

            $count++;
        }

        return $data;
    }

    private function extractCustomFields($ticket): array {
        $fields = array();

        if (!class_exists('DynamicFormEntry')) {
            return $fields;
        }

        try {
            $forms = DynamicFormEntry::forTicket($ticket->getId());
            if (!$forms) {
                return $fields;
            }

            // Skip core fields (subject, priority) — already included above
            $skip = array('subject', 'priority');

            foreach ($forms as $form) {
                $answers = $form->getAnswers();
                if (!$answers) continue;

                foreach ($answers as $answer) {
                    $field = $answer->getField();
                    if ($field && in_array($field->get('name'), $skip)) {
                        continue;
                    }

                    $value = $answer->display();
                    if (!$value) continue;

                    $label = $answer->getLocal('label');
                    if (!$label) continue;

                    $value = strip_tags(trim($value));
                    if ($value === '') continue;

                    $fields[] = array('label' => $label, 'value' => $value);
                }
            }
        } catch (\Throwable $e) {
            // Best-effort — don't fail if custom fields aren't accessible
        }

        return $fields;
    }
}
