# iCal view (`\Temma\Views\ICal`)

Generates an iCalendar stream (calendar events). No template involved.

```php
class Agenda extends \Temma\Web\Controller {
	public function export() {
		$this['ical'] = [
			'name'        => 'My agenda',                 // optional
			'description' => 'Personal events',           // optional
			'events'      => [                            // mandatory
				[
					'name'      => 'Dentist',                       // mandatory
					'dateStart' => '2026-04-07 15:30:00+01:00',     // or 'date' => 'YYYY-MM-DD' for all-day
					'dateEnd'   => '2026-04-07 16:25:00+01:00',
					'uid'       => 'evt-01',                        // recommended (stable identifier)
					// optional: 'description', 'html', 'dateCreation',
					// 'organizerName', 'organizerEmail'
				],
			],
		];
		$this->_view('~ICal');
	}
}
```

- Data variable: `ical` (or `@output`, which takes priority).
- Each event needs either `date` (all-day, `YYYY-MM-DD`) or `dateStart` + `dateEnd`
  (`YYYY-MM-DD hh:mm:ss` with timezone offset or `Z`).
- `$this['filename'] = 'event.ics';` sends the stream as a downloadable attachment.

Reference: https://www.temma.net/en/documentation/view-ical
