class ClubConfig
{
    public string $domain;
    public int $team_id;
    public array $competition_ids;
    public int $season;
    public int $limit_last;
    public int $limit_next;
    public array $active_widgets;
    public bool $enabled;
    public array $custom_labels;
    public string $notes;
    public string $updated_at;
    public string $last_checked;
    public string $status;

    public function __construct(array $data)
    {
        foreach ($data as $key => $value) {
            $this->{$key} = $value;
        }
    }
}
