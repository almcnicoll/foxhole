<?php

// One exception class per failure class named in the spec (§11), so run.php's
// catch blocks can log which stage failed without re-deriving it from message text.
class OctopusFetchException extends RuntimeException
{
}

class ScheduleBuildException extends RuntimeException
{
}

class FoxessPushException extends RuntimeException
{
}

class SolarForecastException extends RuntimeException
{
}
