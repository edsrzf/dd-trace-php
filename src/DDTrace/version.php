<?php

/**
 * Returns the tracer sources versions.
 *
 * @return string
 */
function ddtrace_src_version()
{
    // Must begin with a number for Debian packaging requirements
    // Must use single-quotes for packaging script to work
    return '1.0.0-nightly'; // Update Tracer::VERSION too
}
