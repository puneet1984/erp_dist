<?php

// Date formatting functions
function formatDisplayDate($date)
{
    return date('d-m-Y', strtotime($date));
}

function formatDatabaseDate($date)
{
    return date('Y-m-d', strtotime($date));
}

function formatDate($datetime)
{
    return date('d-m-Y', strtotime($datetime));
}

function formatTime($datetime)
{
    return date('h:i A', strtotime($datetime));
}
