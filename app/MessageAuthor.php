<?php

namespace App;

/**
 * Who wrote a message in an "Erklär's mir" session: the student explaining, or the tutor asking back.
 */
enum MessageAuthor: string
{
    case Student = 'student';
    case Tutor = 'tutor';
}
