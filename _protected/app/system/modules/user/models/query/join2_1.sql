UPDATE [DB_PREFIX]members SET sex = :sex, matchSex = :match_sex, purpose = :purpose, birthDate = :birth_date WHERE profileId = :profile_id LIMIT 1;
