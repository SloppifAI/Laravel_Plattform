<?php

namespace App\Models;

use Database\Factories\ConsentFormFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One version of a school's consent form for the Tuto-Mikro in lessons. A published version is never edited: the school
 * publishes a new one instead, and consents given for an older version count as outdated (Art. 7 Abs. 1 DSGVO:
 * every consent can be traced to the exact text it was given for).
 */
#[Fillable(['school_id', 'version', 'title', 'body', 'purposes', 'published_at', 'created_by'])]
class ConsentForm extends Model
{
    /** @use HasFactory<ConsentFormFactory> */
    use HasFactory;

    /**
     * The purposes parents agree to separately. The audio itself is the basis of the other two. "ai_analysis" is what lets the
     * sound segments (real voices, not pseudonymised) go to the AI provider for speech recognition, and the pseudonymised text for the
     * summary; while the school's AI is on, the Tuto-Mikro stays off for the whole class without it (LessonRecording::purposesFor()).
     *
     * @var array<string, string>
     */
    public const array PURPOSES = [
        'audio' => 'Ton des Unterrichts (Tuto-Mikro)',
        'transcript' => 'Notizen aus der Stunde (aus dem Ton erkannter Text)',
        'ai_analysis' => 'Spracherkennung und Zusammenfassung durch den KI-Anbieter (Ton-Abschnitte; Text nur pseudonymisiert)',
    ];

    /**
     * The same purposes as short words for lists.
     *
     * @var array<string, string>
     */
    public const array PURPOSES_SHORT = ['audio' => 'Ton', 'transcript' => 'Notizen aus der Stunde', 'ai_analysis' => 'KI-Anbieter (Spracherkennung, Zusammenfassung)'];

    /**
     * @return BelongsTo<School, $this>
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<RecordingConsent, $this>
     */
    public function consents(): HasMany
    {
        return $this->hasMany(RecordingConsent::class);
    }

    /**
     * @param  Builder<ConsentForm>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->whereNotNull('published_at');
    }

    /**
     * The text for the public page and the letters. Markdown only: raw HTML is stripped and unsafe links are dropped.
     */
    public function html(): string
    {
        return Str::markdown($this->body, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }

    /**
     * @return array<string, string>
     */
    public function purposeLabels(): array
    {
        return array_intersect_key(self::PURPOSES, array_flip($this->purposes));
    }

    public static function defaultTitle(): string
    {
        return 'Einwilligung zum Tuto-Mikro im Unterricht';
    }

    /**
     * A template in Sie-form for the parents. The school has to read it through and adapt it (with its data protection
     * officer): the values in it come from the school's master data and the platform's retention setting.
     */
    public static function defaultBody(School $school): string
    {
        $sessionDays = (int) config('lernplattform.recordings.session_keep_days', 365);
        $years = (int) config('lernplattform.retention.school_years_kept', 1);
        $notesRetention = $years === 1 ? 'zum Ende des Schuljahres' : "nach {$years} Schuljahren";
        $contact = $school->contact_email ?: 'das Sekretariat der Schule';

        return <<<MARKDOWN
        **{$school->name}** möchte in einzelnen Unterrichtsstunden das **Tuto-Mikro** einschalten. Es erfasst ausschließlich den Ton der Stunde. Dafür brauchen wir Ihre Einwilligung. Sie ist freiwillig.

        ## Was erfasst das Tuto-Mikro?

        - Ausschließlich den Ton des Unterrichts aus dem Mikro der Lehrkraft. Es gibt kein Bild und kein Video.
        - Im Ton können die Stimme und die mündlichen Beiträge Ihres Kindes zu hören sein.
        - Das Tuto-Mikro ist nur an, wenn für alle anwesenden Schüler:innen der Klasse eine Einwilligung vorliegt.

        ## Was passiert mit dem Ton und den Notizen?

        - Der Ton wird in kurzen Abschnitten in Text umgewandelt (Notizen aus der Stunde) und danach sofort gelöscht. Die Plattform speichert keinen Ton.
        - Für die Umwandlung nutzt die Schule ihre KI-Anbindung: Ist sie aktiviert, gehen die kurzen Ton-Abschnitte zur Spracherkennung an den KI-Anbieter. Diese Ton-Abschnitte werden nicht pseudonymisiert: Darin sind echte Stimmen zu hören, auch gesprochene Namen. Ist die KI-Anbindung nicht aktiviert, verlässt der Ton die Plattform nicht.
        - Die Notizen bleiben im Browser der Lehrkraft, bis sie etwas daraus in ihre Unterrichtsnotizen übernimmt. Daraus kann die Lehrkraft eine Zusammenfassung und Hausaufgaben erstellen. Dafür erhält die KI nur pseudonymisierten Text: Namen der Klasse werden vorher durch neutrale Bezeichnungen ersetzt.
        - Ist die KI-Anbindung der Schule aktiviert, läuft das Tuto-Mikro nur, wenn alle anwesenden Eltern auch der Spracherkennung und Zusammenfassung durch den KI-Anbieter unten ausdrücklich zugestimmt haben. Ohne diese Zustimmung bleibt das Tuto-Mikro für die ganze Klasse aus. Ist die KI-Anbindung nicht aktiviert, wird diese Zustimmung nicht gebraucht.
        - Für andere Zwecke werden Ton und Notizen nicht verwendet.

        ## Wer hat Zugriff?

        Nur die Lehrkraft, die die Stunde hält. Ton und Notizen werden nicht an andere Personen oder Stellen weitergegeben, außer an den KI-Anbieter, soweit oben beschrieben.

        ## Wie lange wird gespeichert?

        Ton wird nicht gespeichert. Was die Lehrkraft in ihre Unterrichtsnotizen übernimmt, wird {$notesRetention} gelöscht. Die Plattform vermerkt je Sitzung, wann das Tuto-Mikro lief und für welche Schüler:innen eine Einwilligung vorlag. Dieser Nachweis wird nach {$sessionDays} Tagen gelöscht.

        ## Freiwillig und ohne Nachteile

        Sie entscheiden freiwillig. Wenn Sie nicht einwilligen oder später widerrufen, entstehen Ihrem Kind keine Nachteile. Sie können einzelne Zwecke ablehnen und nur den Rest erlauben, der Ton ist aber die Grundlage der anderen Zwecke. Lehnen Sie die Spracherkennung und Zusammenfassung durch den KI-Anbieter ab, bleibt das Tuto-Mikro aus, solange die Schule die KI-Anbindung nutzt.

        ## Widerruf

        Sie können Ihre Einwilligung jederzeit mit Wirkung für die Zukunft widerrufen. Nutzen Sie dafür denselben Link, über den Sie die Einwilligung erteilt haben, oder schreiben Sie der Schule. Die Rechtmäßigkeit der Verarbeitung bis zum Widerruf bleibt unberührt (Art. 7 Abs. 3 DSGVO). Möchten Sie, dass vorhandene Notizen früher gelöscht werden, wenden Sie sich bitte an die Schule.

        ## Ihre Rechte und Kontakt

        Sie haben das Recht auf Auskunft, Berichtigung, Löschung, Einschränkung der Verarbeitung und Widerspruch sowie das Recht, sich bei der Datenschutzaufsichtsbehörde zu beschweren. Verantwortlich ist {$school->name}, {$school->city}. Kontakt: {$contact}.
        MARKDOWN;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purposes' => 'array',
            'published_at' => 'datetime',
        ];
    }
}
