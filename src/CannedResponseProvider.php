<?php

class CannedResponseProvider {
    private $maxContentLength;

    public function __construct(int $maxContentLength = 500) {
        $this->maxContentLength = $maxContentLength;
    }

    /**
     * Get filtered canned responses relevant to a ticket.
     *
     * @param Ticket $ticket
     * @param int $max Maximum number of responses to return
     * @return array
     */
    public function getForTicket($ticket, int $max = 15): array {
        $deptId = $ticket->getDeptId();
        $responses = array();

        $query = Canned::objects()->filter(array(
            'isenabled' => 1,
        ));

        foreach ($query as $canned) {
            $cannedDeptId = $canned->getDeptId();

            // Include global responses (dept_id=0) and department-matching ones
            if ($cannedDeptId != 0 && $cannedDeptId != $deptId) {
                continue;
            }

            $content = strip_tags($canned->getResponse());
            $content = preg_replace('/\s+/', ' ', trim($content));

            $responses[] = array(
                'id' => $canned->getId(),
                'title' => $canned->getTitle(),
                'content' => $content,
                'dept_id' => $cannedDeptId,
            );

            if (count($responses) >= $max) {
                break;
            }
        }

        return $responses;
    }
}
