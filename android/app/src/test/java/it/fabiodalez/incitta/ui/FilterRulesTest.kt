package it.fabiodalez.incitta.ui

import org.junit.Assert.*
import org.junit.Test
import it.fabiodalez.incitta.data.filterQuery

class FilterRulesTest {
    @Test fun removingPositionPreservesAllOtherFilters() {
        val selected = mapOf("preset" to "tomorrow", "categories" to "teatro", "time_of_day" to "evening", "accessible" to "1", "near" to "45,11", "radius_km" to "25", "sort" to "distance")
        assertEquals(selected - setOf("near", "radius_km", "sort"), withoutSearchPosition(selected))
        assertTrue(removesSearchFilters(selected, withoutSearchPosition(selected)))
    }
    @Test fun clearingPositionDoesNotClearAnotherSort() {
        assertEquals(mapOf("sort" to "time"), withoutSearchPosition(mapOf("sort" to "time", "near" to "45,11")))
    }
    @Test fun resetAndPartialCategoryRemovalAlwaysWork() {
        val previous = mapOf("categories" to "cinema,teatro", "preset" to "today")
        assertTrue(removesSearchFilters(previous, emptyMap()))
        assertTrue(removesSearchFilters(previous, mapOf("categories" to "cinema")))
    }
    @Test fun newFeaturesDatesCategoriesAndRadiusRequireValidation() {
        val previous = mapOf("preset" to "tomorrow", "radius_km" to "25", "categories" to "teatro")
        for ((key, value) in listOf("preset" to "today", "radius_km" to "5", "accessible" to "1", "categories" to "cinema")) {
            assertFalse(removesSearchFilters(previous, previous + (key to value)))
        }
    }
    @Test fun apiQueryEncodesSearchAndNormalizesPrice() {
        assertEquals("price=max%3A10&q=teatro+%26+danza", filterQuery(mapOf("price" to "max10"), "teatro & danza"))
        assertTrue(filterQuery(mapOf("price" to "max20"), "").contains("max%3A20"))
    }
    @Test fun aSingleEndDateHasTheSameMeaningAsLaravel() {
        assertTrue(filterQuery(mapOf("to" to "2026-09-11"), "").contains("from=2026-09-11"))
        assertTrue(filterQuery(mapOf("from" to "2026-09-10", "to" to "2026-09-11"), "").contains("from=2026-09-10"))
    }
}
